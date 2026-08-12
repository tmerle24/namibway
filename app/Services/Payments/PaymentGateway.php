<?php

namespace App\Services\Payments;

use App\Enums\PaymentCollector;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Services\Payments\DTOs\PaymentRequest;
use App\Services\Payments\Providers\DTOs\ProviderOutcome;
use App\Services\Payments\Providers\PaymentProvider;
use App\Services\Payments\Providers\PaymentProviderFactory;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Everything above the provider interface. Nothing in this file names a
 * gateway, which is the claim slice 5 has to be able to make.
 *
 * Two responsibilities and no more:
 *
 * - **Start an attempt.** Work out what is owed, convert it into something the
 *   chosen gateway can actually settle, and hand back somewhere to send the
 *   guest.
 * - **Settle one.** Ask the gateway what happened and, if money moved, record
 *   it — through PaymentRecorder like every other payment, so a card capture
 *   and a note of cash at the desk are the same row.
 *
 * ## Settling is idempotent because the database says so
 *
 * A gateway will deliver the same callback more than once, and a guest will
 * return from the redirect while a webhook is still in flight. Both paths call
 * settle(); `payments.payment_intent_id` is unique, so the second one cannot
 * produce a second credit. The status check below is a courtesy that saves a
 * query — the constraint is the mechanism.
 */
class PaymentGateway
{
    public function __construct(
        private readonly PaymentRecorder $recorder,
        private readonly PaymentProviderFactory $providers = new PaymentProviderFactory,
    ) {}

    /**
     * Ask a guest for money, and get back the link to send them.
     *
     * An existing open attempt for the same purpose is reused rather than
     * duplicated: a guest who clicks the emailed link twice should see the
     * same page, not start a second transaction the property then has to
     * reconcile.
     */
    public function start(
        Reservation $reservation,
        PaymentPurpose $purpose = PaymentPurpose::Deposit,
        ?float $amount = null,
        ?PaymentProvider $provider = null,
    ): PaymentIntent {
        $provider ??= $this->providers->default();
        $due = $amount ?? $this->amountFor($reservation, $purpose);

        if (Money::cents($due) <= 0) {
            throw new InvalidArgumentException(
                'There is nothing to ask this guest for. Price the stay, or set a deposit above zero.'
            );
        }

        $existing = PaymentIntent::query()
            ->where('reservation_id', $reservation->id)
            ->where('purpose', $purpose->value)
            ->where('status', PaymentIntentStatus::Pending->value)
            ->where('provider', $provider->key())
            ->latest('id')
            ->first();

        if ($existing instanceof PaymentIntent && Money::equals($existing->amount, $due)) {
            return $existing;
        }

        $charge = $this->chargeableAmount($provider, $due, $reservation->currency);

        $intent = MoneyWriteGuard::allow(fn (): PaymentIntent => PaymentIntent::create([
            // Long and random: this is a URL and a callback key, and an
            // incrementing id here would let anybody read the booking next
            // door by subtracting one.
            'reference' => (string) Str::ulid().Str::lower(Str::random(16)),
            'reservation_id' => $reservation->id,
            'provider' => $provider->key(),
            'purpose' => $purpose,
            'status' => PaymentIntentStatus::Pending,
            'amount' => $due,
            'currency' => strtoupper($reservation->currency),
            'charge_amount' => $charge['amount'],
            'charge_currency' => $charge['currency'],
            'exchange_rate' => $charge['rate'],
        ]));

        $started = $provider->createIntent($intent);

        return MoneyWriteGuard::allow(function () use ($intent, $started): PaymentIntent {
            $intent->provider_reference = $started->providerReference;
            $intent->meta = array_merge($intent->meta ?? [], $started->meta, [
                'redirect_url' => $started->redirectUrl,
            ]);
            $intent->save();

            return $intent;
        });
    }

    /** Where to send the guest. Read off the intent so a reused one still works. */
    public function redirectUrlFor(PaymentIntent $intent): ?string
    {
        $url = $intent->meta['redirect_url'] ?? null;

        return is_string($url) ? $url : null;
    }

    /**
     * Ask the gateway what happened and record it if money moved.
     *
     * Safe to call from the returning browser and from the webhook, in either
     * order, any number of times.
     */
    public function settle(PaymentIntent $intent, ?ProviderOutcome $outcome = null): PaymentIntent
    {
        // Already answered. Nothing to do, and importantly no second payment.
        if (! $intent->status->isOpen()) {
            return $intent;
        }

        $provider = $this->providers->make($intent->provider);

        // The provider is asked even when a callback already told us, because
        // a webhook body is a claim and `capture()` is the gateway's own
        // record. The passed outcome is what decides whether it is worth
        // asking at all.
        $result = $outcome !== null && ! $outcome->status->isOpen()
            ? $provider->capture($intent)
            : ($outcome ?? $provider->capture($intent));

        if ($result->status->isOpen()) {
            return $intent;
        }

        return DB::transaction(function () use ($intent, $result): PaymentIntent {
            // Re-read under the transaction: two settlements racing (the guest
            // returning while the webhook lands) both passed the check above.
            $fresh = PaymentIntent::query()->lockForUpdate()->find($intent->id);

            if (! $fresh instanceof PaymentIntent || ! $fresh->status->isOpen()) {
                return $fresh ?? $intent;
            }

            if ($result->isSuccessful()) {
                $this->recordPaymentFor($fresh, $result);
            }

            return MoneyWriteGuard::allow(function () use ($fresh, $result): PaymentIntent {
                $fresh->status = $result->status;
                $fresh->provider_reference = $result->providerReference ?? $fresh->provider_reference;
                $fresh->completed_at = now();
                $fresh->meta = array_merge($fresh->meta ?? [], array_filter([
                    'outcome_message' => $result->message,
                ]), $result->meta);
                $fresh->save();

                return $fresh;
            });
        });
    }

    /**
     * Send money back through the gateway it came from.
     *
     * The negative payment is written whatever the gateway says, because the
     * gateway is the record of the *instruction* and the folio is the record
     * of the money. A refund the provider refuses is reported to the caller
     * and leaves no row; one it accepts lands like any other refund.
     */
    public function refund(Payment $payment, ?float $amount = null, ?int $recordedBy = null): Payment
    {
        $intent = $payment->paymentIntent;

        if ($intent === null) {
            throw new InvalidArgumentException(
                'That payment was not taken through a payment provider. Record the refund at the desk instead.'
            );
        }

        $due = $amount ?? $payment->amount;

        if (Money::cents($due) <= 0) {
            throw new InvalidArgumentException('A refund has to be for something.');
        }

        $outcome = $this->providers->make($intent->provider)->refund($payment, $due);

        if (! $outcome->isSuccessful()) {
            throw new InvalidArgumentException(
                $outcome->message ?? 'The payment provider refused the refund.'
            );
        }

        $reservation = $payment->reservation;

        if ($reservation === null) {
            throw new InvalidArgumentException('That payment is not attached to a stay.');
        }

        return $this->recorder->record(new PaymentRequest(
            reservation: $reservation,
            amount: -$due,
            method: PaymentMethod::Online,
            collectedBy: PaymentCollector::NamibWay,
            status: PaymentStatus::Cleared,
            reference: $outcome->providerReference,
            recordedBy: $recordedBy,
            note: 'Refunded through '.$intent->provider.'.',
        ));
    }

    /**
     * The one place a successful attempt becomes money on the folio.
     *
     * Through PaymentRecorder like everything else — the whole reason the
     * ledger was built first is that a gateway capture and a note of cash at
     * the desk are the same row with a different method.
     */
    private function recordPaymentFor(PaymentIntent $intent, ProviderOutcome $result): void
    {
        $reservation = $intent->reservation;

        if ($reservation === null) {
            Log::warning('A payment settled against a stay that no longer exists.', [
                'intent' => $intent->reference,
            ]);

            return;
        }

        $this->recorder->record(new PaymentRequest(
            reservation: $reservation,
            amount: $intent->amount,
            method: PaymentMethod::Online,
            // A provider we hold the merchant account with. Under the agency
            // model the property collects outside the system, so no intent
            // exists to reach this line.
            collectedBy: PaymentCollector::NamibWay,
            // The gateway has confirmed it. That is exactly what `cleared`
            // means, and the distinction from `recorded` is why it exists.
            status: PaymentStatus::Cleared,
            // Only where the guest was charged in another currency; otherwise
            // the redundant copies stay out of the row.
            receivedAmount: $intent->isConverted() ? $intent->charge_amount : null,
            receivedCurrency: $intent->isConverted() ? $intent->charge_currency : null,
            exchangeRate: $intent->isConverted() ? $intent->exchange_rate : null,
            reference: $result->providerReference ?? $intent->provider_reference,
            note: $intent->purpose->label().' paid online.',
            paymentIntentId: $intent->id,
        ));
    }

    /** What this purpose is worth on this stay. */
    private function amountFor(Reservation $reservation, PaymentPurpose $purpose): float
    {
        return match ($purpose) {
            PaymentPurpose::Deposit => (float) ($reservation->deposit_amount ?? 0.0),
            PaymentPurpose::Full => (float) ($reservation->total_amount ?? 0.0),
            // What is left after everything already recorded — which is what
            // "the balance" means to the guest being asked for it.
            PaymentPurpose::Balance => round(
                (float) ($reservation->total_amount ?? 0.0) - (float) $reservation->paid_amount,
                2,
            ),
        };
    }

    /**
     * What the gateway is actually asked for.
     *
     * The folio is in NAD and no provider available to us settles NAD, so
     * something has to give. NAD is pegged 1:1 to ZAR under the Common
     * Monetary Area (`config/currencies.php`), which makes ZAR the honest
     * answer rather than a conversion nobody can check — and the rate is
     * stored on the intent so a later refund returns the same money, and so a
     * peg that ends does not rewrite what was charged.
     *
     * @return array{amount: float, currency: string, rate: float}
     */
    private function chargeableAmount(PaymentProvider $provider, float $amount, string $currency): array
    {
        $currency = strtoupper($currency);
        $supported = array_map('strtoupper', $provider->supportedCurrencies());

        if ($supported === [] || in_array($currency, $supported, true)) {
            return ['amount' => $amount, 'currency' => $currency, 'rate' => 1.0];
        }

        /** @var array<string, array<string, float>> $pegs */
        $pegs = (array) config('payments.currency_pegs', []);

        foreach ($pegs[$currency] ?? [] as $peggedTo => $rate) {
            if (in_array(strtoupper($peggedTo), $supported, true)) {
                return [
                    'amount' => Money::fromCents((int) round(Money::cents($amount) * $rate)),
                    'currency' => strtoupper($peggedTo),
                    'rate' => (float) $rate,
                ];
            }
        }

        throw new InvalidArgumentException(
            "This booking is in {$currency} and ".$provider->label().' cannot settle it. '
            .'Either the provider or the property’s selling currency has to change.'
        );
    }
}
