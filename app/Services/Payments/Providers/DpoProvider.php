<?php

namespace App\Services\Payments\Providers;

use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Services\Payments\Providers\DTOs\ProviderCallback;
use App\Services\Payments\Providers\DTOs\ProviderIntent;
use App\Services\Payments\Providers\DTOs\ProviderOutcome;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DPO Pay by Network — the one candidate that actually covers Namibia.
 *
 * ## Why this one
 *
 * Checked 2026-08-12 against the list in PAYMENTS.md § 5, and the answer was
 * not close:
 *
 * - **DPO Pay** operates in Namibia with a team in Windhoek, and bills and
 *   settles **in NAD**. Cards, online EFT and mobile money, one documented
 *   public API.
 * - **PayGate** has been absorbed into the same group.
 * - **Peach Payments** is live in South Africa, Kenya and Mauritius; Namibia
 *   is an announced intention, not an account somebody can open. Worth
 *   watching for a second reason — ResRequest, the dominant PMS in this
 *   market, already integrates it.
 * - **Ozow** and **Netcash** are South African bank-to-bank rails; Instant EFT
 *   works only with South African bank accounts. Not applicable.
 * - **PayToday** is genuinely Namibian and run by Nedbank Namibia, but it is a
 *   wallet with a WooCommerce plugin rather than a documented API. A good
 *   *additional* method to ask them about, not a card acquirer.
 * - **FNB / Bank Windhoek / Standard Bank Namibia** do e-commerce acquiring,
 *   but through a relationship and usually behind a gateway rather than a
 *   self-serve API. That is a conversation, not an integration.
 *
 * So this is the implementation to have. It is still **unvalidated** — like
 * every connector in this codebase it has never run against a real account,
 * and the first one will turn up surprises.
 *
 * ## How the flow goes
 *
 * 1. `createToken` with our own reference and the amount, in NAD. DPO answers
 *    with a `TransToken`.
 * 2. The guest is sent to `payv3.php?ID={TransToken}` and pays there.
 * 3. They come back to `RedirectURL`, and DPO can also be configured to ping a
 *    notification URL.
 * 4. **`verifyToken` is the only thing believed.** DPO has no signed webhook,
 *    and its own documentation makes verifying on return mandatory. That is
 *    why PaymentGateway always calls `capture()` whatever a callback said.
 *
 * ## The one thing that needs confirming with DPO before go-live
 *
 * `Result` `000` is documented as "Transaction Paid" and is the only code this
 * class treats as final. **Everything else is reported as pending**, including
 * codes that probably mean declined — because those meanings could not be
 * verified from public documentation, and mapping an unverified code to
 * "failed" is precisely how a payment that settled gets written off. Ask DPO
 * for the verifyToken code table, then add the failure codes to
 * `FAILURE_CODES` below. Until then an abandoned attempt simply stays open,
 * which is visible and harmless; `PTL` gives it an expiry at DPO's end.
 */
class DpoProvider implements PaymentProvider
{
    /** Documented: "Transaction Paid". */
    private const PAID = '000';

    public function __construct(private readonly DpoClient $client) {}

    /**
     * Codes that mean the attempt is over and unpaid.
     *
     * Configured rather than compiled in, and shipped **empty** — see the
     * class comment. An unverified code mapped to "failed" writes off money
     * that may have arrived, and that is the expensive direction to get wrong.
     * Whoever obtains the verifyToken code table from DPO adds them under
     * `payments.providers.dpo.failure_codes`, which is also where a reviewer
     * can see at a glance which codes we claim to understand.
     *
     * @return array<int, string>
     */
    private function failureCodes(): array
    {
        /** @var array<int, string> $codes */
        $codes = config('payments.providers.dpo.failure_codes', []);

        return array_map('strval', $codes);
    }

    public function key(): string
    {
        return 'dpo';
    }

    public function label(): string
    {
        return 'DPO Pay';
    }

    /**
     * NAD, first-class — which is the whole reason this provider exists.
     *
     * DPO settles a long list of African currencies; what matters here is
     * that a Namibian folio needs no conversion at all, so none of
     * PaymentGateway's peg machinery is reached.
     *
     * @return array<int, string>
     */
    public function supportedCurrencies(): array
    {
        /** @var array<int, string> $configured */
        $configured = config('payments.providers.dpo.currencies', ['NAD', 'ZAR', 'USD', 'EUR', 'GBP']);

        return array_map('strtoupper', $configured);
    }

    public function createIntent(PaymentIntent $intent): ProviderIntent
    {
        $reservation = $intent->reservation;

        if (! $reservation instanceof Reservation) {
            throw new RuntimeException('That payment attempt is not attached to a stay.');
        }

        $response = $this->client->createToken(
            transaction: array_filter([
                'PaymentAmount' => number_format($intent->charge_amount, 2, '.', ''),
                'PaymentCurrency' => strtoupper($intent->charge_currency),
                // Ours. `CompanyRefUnique` then makes DPO refuse a duplicate
                // rather than open a second transaction, which turns a retried
                // createToken from a risk into a no-op.
                'CompanyRef' => $intent->reference,
                'CompanyRefUnique' => '1',
                'RedirectURL' => route('payments.return', $intent),
                'BackURL' => route('payments.return', $intent),
                // Payment time limit, in hours. A link that lives forever is a
                // room held forever behind an attempt nobody will finish.
                'PTL' => (string) (int) config('payments.providers.dpo.link_hours', 48),
                'customerEmail' => $reservation->guest_email,
                'customerFirstName' => Str::before($reservation->guest_name, ' ') ?: null,
                'customerLastName' => Str::contains($reservation->guest_name, ' ')
                    ? Str::after($reservation->guest_name, ' ')
                    : null,
                'customerPhone' => $reservation->guest_phone,
            ], fn (?string $value): bool => $value !== null && $value !== ''),
            service: [
                // Configured per DPO account; there is no universal value and
                // a wrong one is rejected at createToken rather than silently.
                'ServiceType' => (string) config('payments.providers.dpo.service_type', ''),
                'ServiceDescription' => $this->describe($intent, $reservation),
                // When the thing being sold is delivered — the arrival.
                'ServiceDate' => $reservation->check_in->format('Y/m/d H:i'),
            ],
        );

        $result = (string) ($response['Result'] ?? '');
        $token = $response['TransToken'] ?? null;

        if ($result !== self::PAID || ! is_string($token) || $token === '') {
            throw new RuntimeException(
                'DPO would not create the transaction: '
                .(is_string($response['ResultExplanation'] ?? null) ? $response['ResultExplanation'] : 'no answer')
                .' ('.($result === '' ? 'no result code' : $result).').'
            );
        }

        return new ProviderIntent(
            redirectUrl: $this->client->checkoutUrl($token),
            // DPO's own reference for the transaction, kept so a callback can
            // be matched either way round.
            providerReference: is_string($response['TransRef'] ?? null) ? $response['TransRef'] : $token,
            meta: ['trans_token' => $token],
        );
    }

    /**
     * `verifyToken` — the only thing believed. Safe to call repeatedly, which
     * it will be: on the guest's return, and again from the notification.
     */
    public function capture(PaymentIntent $intent): ProviderOutcome
    {
        $token = $intent->meta['trans_token'] ?? null;

        if (! is_string($token) || $token === '') {
            return ProviderOutcome::pending('This attempt has no DPO transaction token.');
        }

        $response = $this->client->verifyToken($token);
        $result = (string) ($response['Result'] ?? '');
        $explanation = is_string($response['ResultExplanation'] ?? null)
            ? $response['ResultExplanation']
            : null;

        if ($result === self::PAID) {
            return ProviderOutcome::succeeded(
                is_string($response['TransactionRef'] ?? null) ? $response['TransactionRef'] : $intent->provider_reference,
                array_filter([
                    'dpo_transaction_approval' => $response['TransactionApproval'] ?? null,
                    'dpo_customer_name' => $response['CustomerName'] ?? null,
                ], fn (mixed $value): bool => $value !== null),
            );
        }

        if (in_array($result, $this->failureCodes(), true)) {
            return ProviderOutcome::failed($explanation ?? 'DPO declined the payment.', $intent->provider_reference);
        }

        // Everything else is "not yet" — see the class comment on why that is
        // the safe direction while the code table is unconfirmed.
        return ProviderOutcome::pending(
            $result === '' ? null : 'DPO reports '.$result.($explanation === null ? '' : ': '.$explanation),
        );
    }

    public function refund(Payment $payment, float $amount): ProviderOutcome
    {
        $intent = $payment->paymentIntent;
        $token = is_array($intent?->meta) ? ($intent->meta['trans_token'] ?? null) : null;

        if ($intent === null || ! is_string($token) || $token === '') {
            return ProviderOutcome::failed('That payment did not come through DPO, so it cannot be refunded through it.');
        }

        // In the currency it was charged in, at the rate it was charged at —
        // not today's. A refund converted afresh returns a different amount of
        // money than was taken.
        $chargeAmount = $intent->exchange_rate > 0.0
            ? round($amount * $intent->exchange_rate, 2)
            : $amount;

        $reservation = $intent->reservation;

        $response = $this->client->refundToken(
            $token,
            $chargeAmount,
            'Refund for '.($reservation instanceof Reservation ? $reservation->reference : $intent->reference),
        );

        return (string) ($response['Result'] ?? '') === self::PAID
            ? ProviderOutcome::succeeded($intent->provider_reference)
            : ProviderOutcome::failed(
                is_string($response['ResultExplanation'] ?? null)
                    ? $response['ResultExplanation']
                    : 'DPO refused the refund.',
            );
    }

    /**
     * DPO's notification, which is **not** a signed webhook.
     *
     * So this vouches for nothing about the *outcome* — it returns a pending
     * result whose only job is to say "this attempt is worth asking about".
     * PaymentGateway then calls `capture()`, which asks DPO directly, and that
     * is what decides. An unsigned notification that could settle a booking
     * would be a paid booking anybody could forge with a curl command.
     */
    public function parseCallback(Request $request): ?ProviderCallback
    {
        $reference = $request->input('CompanyRef') ?? $request->input('TransactionToken');

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        return new ProviderCallback(
            $reference,
            ProviderOutcome::pending('DPO sent a notification; the outcome comes from verifyToken.'),
        );
    }

    /** What the guest sees on DPO's page and on their card statement. */
    private function describe(PaymentIntent $intent, Reservation $reservation): string
    {
        $property = $reservation->listing?->name;

        return trim(($property === null ? '' : $property.' — ').$intent->purpose->guestLabel());
    }
}
