<?php

namespace App\Services\Payments\Providers;

use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Services\Payments\Providers\DTOs\ProviderCallback;
use App\Services\Payments\Providers\DTOs\ProviderIntent;
use App\Services\Payments\Providers\DTOs\ProviderOutcome;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Paystack — hosted checkout, verified server-side.
 *
 * ## Read this before planning around it
 *
 * **Paystack does not support Namibia as a merchant country.** Its merchant
 * countries are Nigeria, Ghana, Kenya, South Africa and Côte d'Ivoire, with
 * Egypt and Rwanda more recent. `PAYMENTS.md` § 5 records that NamibWay will
 * be a Namibian company, and a Namibian entity cannot open a Paystack account
 * — the same wall Stripe presented, for the same reason.
 *
 * The route that does work is a **South African entity settling in ZAR**, which
 * is why the currency handling below is not decoration: NAD is pegged 1:1 to
 * ZAR under the Common Monetary Area (`config/currencies.php`), so a Namibian
 * folio is charged in ZAR at that peg and all three currency facts are stored.
 * Whether to have such an entity is a decision about the company, not about
 * code, and nothing here should be read as it having been made.
 *
 * This class exists so that decision is cheap either way: it is one
 * implementation of an interface with several candidates behind it, and
 * swapping it for DPO Pay or a Namibian bank's own acquiring changes this file
 * and a configuration entry.
 *
 * **Unvalidated.** Like every connector in this codebase, it has never run
 * against a real account. Expect the first real integration to turn up
 * surprises.
 *
 * ## How the flow actually goes
 *
 * 1. `transaction/initialize` with our own reference and an amount in minor
 *    units. Paystack returns a hosted checkout URL.
 * 2. The guest pays there and is redirected back to `callback_url`.
 * 3. Independently, Paystack POSTs `charge.success` to the webhook — before,
 *    during or after the redirect. Both paths end in the same place.
 * 4. Nothing is believed until `transaction/verify` says so. A redirect
 *    carries no proof and a webhook body is only as good as its signature, so
 *    the money is confirmed by asking Paystack directly.
 */
class PaystackProvider implements PaymentProvider
{
    public function __construct(private readonly PaystackClient $client) {}

    public function key(): string
    {
        return 'paystack';
    }

    public function label(): string
    {
        return 'Paystack';
    }

    /**
     * What a Paystack account can actually settle. Note the absence of NAD:
     * a Namibian folio is charged in ZAR at the Common Monetary Area peg, and
     * PaymentGateway is what applies that.
     *
     * @return array<int, string>
     */
    public function supportedCurrencies(): array
    {
        /** @var array<int, string> $configured */
        $configured = config('payments.providers.paystack.currencies', ['ZAR']);

        return array_map('strtoupper', $configured);
    }

    public function createIntent(PaymentIntent $intent): ProviderIntent
    {
        $reservation = $intent->reservation;
        $email = $reservation instanceof Reservation ? $reservation->guest_email : null;

        if (! is_string($email) || $email === '') {
            // Paystack requires a customer email and there is no sensible
            // placeholder: a made-up address means the receipt goes nowhere
            // and a dispute has no counterparty. Better to refuse here than to
            // produce a link that fails at the gateway.
            throw new RuntimeException(
                'Paystack needs the guest’s email address, and this booking has none. '
                .'Add one to the booking before sending a payment link.'
            );
        }

        $response = $this->client->initialize([
            'email' => $email,
            // Minor units, as an integer. Paystack rejects a float, and a
            // rounding error here is money.
            'amount' => (int) round($intent->charge_amount * 100),
            'currency' => strtoupper($intent->charge_currency),
            // Our reference, not theirs. It is what a callback is matched on,
            // and generating it before Paystack has seen the attempt is what
            // makes an interrupted initialize recoverable.
            'reference' => $intent->reference,
            'callback_url' => route('payments.return', $intent),
            'metadata' => [
                'reservation_reference' => $reservation->reference,
                'purpose' => $intent->purpose->value,
            ],
        ]);

        $url = $response['data']['authorization_url'] ?? null;

        if (! is_string($url)) {
            throw new RuntimeException('Paystack did not return a checkout URL for '.$intent->reference.'.');
        }

        return new ProviderIntent(
            redirectUrl: $url,
            providerReference: is_string($response['data']['reference'] ?? null)
                ? $response['data']['reference']
                : $intent->reference,
            meta: ['access_code' => $response['data']['access_code'] ?? null],
        );
    }

    /**
     * Ask Paystack what happened. This is the only thing believed — not the
     * redirect, not the webhook body.
     */
    public function capture(PaymentIntent $intent): ProviderOutcome
    {
        $response = $this->client->verify($intent->provider_reference ?? $intent->reference);

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $status = is_string($data['status'] ?? null) ? $data['status'] : '';

        return match ($status) {
            'success' => ProviderOutcome::succeeded(
                is_string($data['reference'] ?? null) ? $data['reference'] : null,
                ['channel' => $data['channel'] ?? null, 'paid_at' => $data['paid_at'] ?? null],
            ),
            'failed', 'reversed' => ProviderOutcome::failed(
                is_string($data['gateway_response'] ?? null) ? $data['gateway_response'] : 'Paystack declined the payment.',
            ),
            'abandoned' => ProviderOutcome::abandoned(),
            // Anything else — including an empty response — is "not yet".
            // Treating an unknown status as a failure is how a payment that
            // settles a minute later ends up with nowhere to land.
            default => ProviderOutcome::pending($status === '' ? null : 'Paystack reports: '.$status),
        };
    }

    public function refund(Payment $payment, float $amount): ProviderOutcome
    {
        $intent = $payment->paymentIntent;

        if ($intent === null) {
            return ProviderOutcome::failed('That payment did not come through Paystack, so it cannot be refunded through it.');
        }

        // Refunded in the currency it was charged in, at the rate it was
        // charged at — not today's. A refund converted at a new rate returns a
        // different amount of money than was taken.
        $chargeAmount = $intent->exchange_rate > 0.0
            ? round($amount * $intent->exchange_rate, 2)
            : $amount;

        $response = $this->client->refund([
            'transaction' => $intent->provider_reference ?? $intent->reference,
            'amount' => (int) round($chargeAmount * 100),
        ]);

        return ($response['status'] ?? false) === true
            ? ProviderOutcome::succeeded(is_string($response['data']['id'] ?? null) ? $response['data']['id'] : null)
            : ProviderOutcome::failed(is_string($response['message'] ?? null) ? $response['message'] : 'Paystack refused the refund.');
    }

    /**
     * Paystack signs the raw body with the secret key (HMAC SHA-512, header
     * `x-paystack-signature`).
     *
     * Verified against the **raw** body rather than the parsed array, because
     * re-encoding JSON changes bytes — key order, escaping, spacing — and the
     * signature is over bytes. That is the classic way a webhook check
     * silently never matches.
     *
     * Returns null rather than throwing on anything unrecognised: Paystack
     * posts events we do not care about, and a 500 on those is how a webhook
     * gets disabled at the other end.
     */
    public function parseCallback(Request $request): ?ProviderCallback
    {
        $signature = $request->header('x-paystack-signature');
        $secret = (string) config('payments.providers.paystack.secret_key');

        if (! is_string($signature) || $secret === '') {
            return null;
        }

        $expected = hash_hmac('sha512', $request->getContent(), $secret);

        // Constant time: a timing oracle on a signature check is a real
        // attack, and hash_equals costs nothing.
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $event = $request->input('event');
        $reference = $request->input('data.reference');

        if (! is_string($reference)) {
            return null;
        }

        $outcome = match ($event) {
            'charge.success' => ProviderOutcome::succeeded($reference),
            'charge.failed' => ProviderOutcome::failed('Paystack reported a failed charge.', $reference),
            default => null,
        };

        return $outcome === null ? null : new ProviderCallback($reference, $outcome);
    }
}
