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

    /**
     * Keep what the gateway said about an attempt we could not settle.
     *
     * "We asked and it is still not paid" is a real state and a common one —
     * a guest who has opened the page and not finished, a bank that has not
     * answered. Somebody will be looking at it when a guest insists they paid.
     */
    private function noteOutcome(PaymentIntent $intent, ?string $message): PaymentIntent
    {
        if ($message === null) {
            return $intent;
        }

        return MoneyWriteGuard::allow(function () use ($intent, $message): PaymentIntent {
            $intent->meta = array_merge($intent->meta ?? [], [
                'outcome_message' => $message,
            ]);
            $intent->save();

            return $intent;
        });
    }

    /**
     * Start an attempt and hand back the address to send the guest to.
     *
     * The one call anything wanting to *ask* for money needs — the partner
     * panel, a confirmation that goes out with a payment link, and whatever
     * asks next. It throws rather than returning null on both refusals a
     * caller has to be able to tell a person about: nothing to ask for, and a
     * provider that gave us nowhere to send them.
     *
     * That second case used to fall back to this application's own demo
     * checkout. It is not a fallback: for a real provider that route is a 404
     * by design, and a page that takes a "pay" click without taking money is
     * the last thing to hand a guest.
     */
    public function linkFor(
        Reservation $reservation,
        PaymentPurpose $purpose = PaymentPurpose::Deposit,
        ?float $amount = null,
    ): string {
        $intent = $this->start($reservation, $purpose, $amount);

        $url = $this->redirectUrlFor($intent);

        if ($url === null) {
            throw new InvalidArgumentException(
                'The payment provider did not give us a link to send. Try again in a moment, or take the payment at the desk.'
            );
        }

        return $url;
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

        /*
         * The gateway is always asked, whatever a callback said.
         *
         * A callback tells us *when* to look; `capture()` is what tells us
         * what happened. That split is not caution for its own sake — it is
         * the only arrangement that works across the gateways we might
         * actually use. One of them signs its webhook and still tells you to
         * verify afterwards; another has no signed notification at all and
         * expects the verify on the guest's return. A design that believed the
         * body would be wrong for the first and unsafe for the second.
         *
         * It also means a webhook can never set an amount, which is the
         * difference between a payment and a forged one.
         */
        $result = $provider->capture($intent);

        if ($result->status->isOpen()) {
            // Nothing to record on the folio, but what the gateway said is
            // kept: "we asked and it is still not paid, and here is why" is
            // exactly what somebody debugging a missing payment needs, and an
            // attempt that sat open with no explanation would be a dead end.
            // The capture message wins over the callback's — it is the
            // authoritative one.
            return $this->noteOutcome($intent, $result->message ?? $outcome?->message);
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
     * PayGate (our Namibia gateway) settles NAD natively, so a NAD folio
     * passes straight through with no conversion. For any other provider whose
     * `supportedCurrencies()` does not include NAD, the NAD↔ZAR peg in
     * `config/payments.currency_pegs` is applied — NAD is pegged 1:1 to ZAR
     * under the Common Monetary Area. The rate is stored on the intent so a
     * later refund returns the same money, and so a peg that ends does not
     * rewrite what was charged.
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
