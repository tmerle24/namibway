<?php

namespace App\Services\Payments\Providers;

use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Services\Payments\Providers\DTOs\ProviderCallback;
use App\Services\Payments\Providers\DTOs\ProviderIntent;
use App\Services\Payments\Providers\DTOs\ProviderOutcome;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayGate PayWeb3 — the gateway that sits on top of DPO Pay's Namibia
 * acquiring infrastructure.
 *
 * ## Context
 *
 * DPO Group acquired Virtual Card Services (VCS) Namibia, which now trades as
 * Network. The merchant-facing gateway is PayGate PayWeb3; DPO directpay (at
 * secure.3gdirectpay.com) is a separate integration used elsewhere in Africa.
 * This provider targets PayWeb3.
 *
 * ## How the flow goes
 *
 * 1. `createIntent()` posts to initiate.trans and gets back a PAY_REQUEST_ID
 *    plus a CHECKSUM. Both are stored in the intent's meta.
 * 2. The guest is sent to `pay/{intent}/go`, which renders an auto-submitting
 *    form that POSTs PAY_REQUEST_ID and CHECKSUM to PayGate's process.trans.
 * 3. PayGate posts to our NOTIFY_URL (`api/payments/callback/paygate`) once the
 *    guest has paid, and then redirects the guest back to a PayGate-specific
 *    return route that bounces them on to the standard `payments.return`.
 * 4. `capture()` calls query.trans, which is the only source of truth — neither
 *    the NOTIFY body nor the return redirect is believed on its own.
 *
 * ## The checksum
 *
 * PayGate's security model is an MD5 of all non-empty field values (in array
 * order) with the encryption key appended. This is checked on every inbound
 * event: NOTIFY callbacks, and the query response. An incoming event that fails
 * the checksum is treated as "not from PayGate" and ignored rather than raising
 * an error — a 500 on a bad request tells an attacker they found the right URL.
 *
 * ## Why refunds are not programmatic
 *
 * PayGate confirmed in pre-contract correspondence (2026-08-17) that refunds
 * are initiated manually through the Merchant Portal and there is no refund API
 * on a standard PayWeb3 terminal. `refund()` therefore reports this clearly
 * rather than silently failing. If PayGate later enables refund API access on
 * our terminal, implement it in PayGateClient::refund() and update this method.
 *
 * ## TRANSACTION_STATUS codes from query.trans
 *
 * 0 = Not Done, 1 = Approved, 2 = Declined, 3 = Cancelled,
 * 4 = User Cancelled, 5 = Received by PayGate, 7 = Settlement Voided
 */
class PayGateProvider implements PaymentProvider
{
    private const STATUS_APPROVED = 1;

    private const STATUS_DECLINED = 2;

    private const STATUS_CANCELLED = 3;

    private const STATUS_USER_CANCELLED = 4;

    private const STATUS_SETTLEMENT_VOIDED = 7;

    public function __construct(private readonly PayGateClient $client) {}

    public function key(): string
    {
        return 'paygate';
    }

    public function label(): string
    {
        return 'PayGate by Network (Namibia, settles NAD)';
    }

    /**
     * NAD only — confirmed by DPO/Network in pre-contract correspondence.
     * Processing and settlement are NAD; the merchant cannot settle in another
     * currency through this account.
     *
     * @return array<int, string>
     */
    public function supportedCurrencies(): array
    {
        return ['NAD'];
    }

    public function createIntent(PaymentIntent $intent): ProviderIntent
    {
        $reservation = $intent->reservation;

        if (! $reservation instanceof Reservation) {
            throw new RuntimeException('That payment attempt is not attached to a stay.');
        }

        // PayGate requires AMOUNT in whole cents (no decimal point).
        $amountCents = (string) Money::cents((float) $intent->charge_amount);

        // REFERENCE is echoed back in NOTIFY and in query responses. We use
        // the intent ID (numeric, short, unique) rather than the full reference
        // so it stays within any field length PayGate imposes. The PAY_REQUEST_ID
        // is the primary lookup key for callbacks.
        $reference = (string) $intent->id;

        $fields = array_filter([
            'REFERENCE' => $reference,
            'AMOUNT' => $amountCents,
            'CURRENCY' => strtoupper($intent->charge_currency),
            'RETURN_URL' => route('payments.paygate.return', $intent),
            'TRANSACTION_DATE' => now()->format('Y-m-d H:i:s'),
            'LOCALE' => 'en',
            'COUNTRY' => (string) config('payments.providers.paygate.country', 'NAM'),
            'EMAIL' => $reservation->guest_email ?? '',
            'NOTIFY_URL' => route('payments.callback', 'paygate'),
        ], fn (?string $v): bool => $v !== null && $v !== '');

        $response = $this->client->initiate($fields);

        if (isset($response['ERROR']) && $response['ERROR'] !== '') {
            throw new RuntimeException(
                'PayGate refused the transaction: '.$response['ERROR']
                .' (code '.$response['ERROR'].').'
            );
        }

        $payRequestId = $response['PAY_REQUEST_ID'] ?? '';
        $processChecksum = $response['CHECKSUM'] ?? '';

        if ($payRequestId === '') {
            throw new RuntimeException(
                'PayGate did not return a PAY_REQUEST_ID. The gateway may be unavailable.'
            );
        }

        return new ProviderIntent(
            // The guest visits this URL, which renders an auto-submitting form
            // that POSTs to PayGate's process.trans.
            redirectUrl: route('payments.paygate.redirect', $intent),
            // PAY_REQUEST_ID is stored as provider_reference so the callback
            // controller can find the intent when PayGate echoes it back.
            providerReference: $payRequestId,
            meta: [
                'pay_request_id' => $payRequestId,
                'process_checksum' => $processChecksum,
                'paygate_reference' => $reference,
            ],
        );
    }

    /**
     * Ask PayGate what actually happened. The only thing believed — see class
     * comment.
     */
    public function capture(PaymentIntent $intent): ProviderOutcome
    {
        $payRequestId = $intent->meta['pay_request_id'] ?? null;
        $reference = $intent->meta['paygate_reference'] ?? null;

        if (! is_string($payRequestId) || $payRequestId === '') {
            return ProviderOutcome::pending('This attempt has no PayGate PAY_REQUEST_ID.');
        }

        if (! is_string($reference) || $reference === '') {
            return ProviderOutcome::pending('This attempt has no PayGate reference.');
        }

        $response = $this->client->query($payRequestId, $reference);

        if (! $this->client->verifyChecksum($response)) {
            Log::warning('PayGate query returned an invalid checksum.', [
                'intent' => $intent->reference,
                'pay_request_id' => $payRequestId,
            ]);

            return ProviderOutcome::pending('PayGate returned a response we could not verify.');
        }

        $status = isset($response['TRANSACTION_STATUS'])
            ? (int) $response['TRANSACTION_STATUS']
            : -1;

        $transactionId = $response['TRANSACTION_ID'] ?? null;
        $resultDesc = $response['RESULT_DESC'] ?? null;

        return match ($status) {
            self::STATUS_APPROVED => ProviderOutcome::succeeded(
                is_string($transactionId) && $transactionId !== '' ? $transactionId : $intent->provider_reference,
                array_filter([
                    'paygate_auth_code' => $response['AUTH_CODE'] ?? null,
                    'paygate_result_desc' => $resultDesc,
                ], fn (mixed $v): bool => $v !== null && $v !== ''),
            ),
            self::STATUS_DECLINED, self::STATUS_CANCELLED, self::STATUS_SETTLEMENT_VOIDED => ProviderOutcome::failed(
                is_string($resultDesc) && $resultDesc !== '' ? $resultDesc : 'PayGate declined the payment.',
                is_string($transactionId) && $transactionId !== '' ? $transactionId : null,
            ),
            self::STATUS_USER_CANCELLED => ProviderOutcome::abandoned('The guest cancelled the payment at PayGate.'),
            default => ProviderOutcome::pending(
                is_string($resultDesc) && $resultDesc !== '' ? 'PayGate reports: '.$resultDesc : null,
            ),
        };
    }

    /**
     * Refunds are not available through the PayGate PayWeb3 API — they must be
     * initiated manually in the PayGate Merchant Portal. This is confirmed in
     * DPO's pre-contract response (question 3.6, 2026-08-17).
     *
     * If the terminal is later configured for refund API access, implement
     * PayGateClient::refund() and replace this method body.
     */
    public function refund(Payment $payment, float $amount): ProviderOutcome
    {
        return ProviderOutcome::failed(
            'PayGate refunds must be initiated through the PayGate Merchant Portal. '
            .'Log in, find the transaction, and use the refund action there.'
        );
    }

    /**
     * Verify and parse PayGate's NOTIFY callback.
     *
     * The NOTIFY is a server-to-server POST from PayGate carrying all the
     * transaction fields plus a CHECKSUM we can verify — unlike DPO's
     * notification, this one is signed and can tell us something about the
     * outcome. `PaymentGateway::settle()` will still call `capture()` to
     * confirm via query.trans, so the outcome here is additional context, not
     * the final answer.
     */
    public function parseCallback(Request $request): ?ProviderCallback
    {
        /** @var array<string, string> $fields */
        $fields = $request->all();

        // Only handle notifications from our own terminal.
        $incomingId = $fields['PAYGATE_ID'] ?? null;

        if ($incomingId !== $this->client->paygateId()) {
            return null;
        }

        if (! $this->client->verifyChecksum($fields)) {
            return null;
        }

        // PAY_REQUEST_ID matches payment_intents.provider_reference, which is
        // how the callback controller finds the intent. REFERENCE (our short
        // numeric ref) would also work but PAY_REQUEST_ID is authoritative.
        $payRequestId = $fields['PAY_REQUEST_ID'] ?? null;

        if (! is_string($payRequestId) || $payRequestId === '') {
            return null;
        }

        $status = isset($fields['TRANSACTION_STATUS'])
            ? (int) $fields['TRANSACTION_STATUS']
            : -1;

        $transactionId = $fields['TRANSACTION_ID'] ?? null;
        $resultDesc = $fields['RESULT_DESC'] ?? null;

        $outcome = match ($status) {
            self::STATUS_APPROVED => ProviderOutcome::succeeded(
                is_string($transactionId) && $transactionId !== '' ? $transactionId : null,
            ),
            self::STATUS_DECLINED, self::STATUS_CANCELLED, self::STATUS_SETTLEMENT_VOIDED => ProviderOutcome::failed(
                is_string($resultDesc) && $resultDesc !== '' ? $resultDesc : null,
            ),
            self::STATUS_USER_CANCELLED => ProviderOutcome::abandoned(),
            default => ProviderOutcome::pending(),
        };

        return new ProviderCallback($payRequestId, $outcome);
    }
}
