<?php

namespace App\Services\Payments;

use App\Enums\FolioStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Inventory\InventoryWriter;
use App\Services\Payments\DTOs\PaymentRequest;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one write path for money.
 *
 * Everything that inserts into `payments` comes through here, exactly as every
 * inventory mutation comes through InventoryWriter — same rule, same two-layer
 * enforcement (MoneyWriteGuard at runtime, an architecture test for the
 * query-builder half). See MoneyWriteGuard for why.
 *
 * ## Nothing is ever edited
 *
 * There is no update() on this class and there will not be one. A payment
 * entered wrongly is *reversed* — a negative row pointing back at it, both
 * rows kept — and the corrected payment is entered fresh. The only field that
 * changes after creation is the status, and only in the one direction a late
 * fact travels: recorded → cleared, or recorded → failed.
 *
 * ## The folio total is a result, not a query
 *
 * Every write here recomputes `reservations.paid_amount` and
 * `payment_status` from the stay's payments and writes them down. The arrivals
 * board, the unpaid list and the stay drawer then all read one number instead
 * of each summing their own and disagreeing — the same rule as the price, for
 * the same reason.
 *
 * Recomputed from the rows rather than incremented, deliberately. An increment
 * is one lost update away from a folio that is quietly wrong for months; a sum
 * inside the same transaction as the insert cannot drift from what the rows
 * say.
 *
 * ## Every comparison is in cents
 *
 * The columns are decimal(12,2) and the house convention casts them to float.
 * No decision here is made on those floats — see Money::cents().
 */
class PaymentRecorder
{
    public function __construct(private readonly InventoryWriter $inventory) {}

    /**
     * Record money moving against a stay. Negative for money going back.
     */
    public function record(PaymentRequest $request): Payment
    {
        if (Money::cents($request->amount) === 0) {
            throw new InvalidArgumentException('A payment of nothing is not a payment.');
        }

        return DB::transaction(function () use ($request): Payment {
            $payment = MoneyWriteGuard::allow(fn (): Payment => Payment::create([
                'reservation_id' => $request->reservation->id,
                'amount' => $request->amount,
                'currency' => $request->currency(),
                'received_amount' => $request->receivedAmount,
                'received_currency' => $request->receivedCurrency === null
                    ? null
                    : strtoupper($request->receivedCurrency),
                'exchange_rate' => $request->exchangeRate,
                'received_at' => $request->receivedAt,
                'method' => $request->method,
                'collected_by' => $request->collectedBy,
                'status' => $request->initialStatus(),
                'reference' => $request->reference,
                'payment_intent_id' => $request->paymentIntentId,
                'recorded_by' => $request->recordedBy,
                'note' => $request->note,
            ]));

            $this->refreshFolio($request->reservation);

            return $payment;
        });
    }

    /**
     * Take a payment back because it should not have been recorded.
     *
     * Not the same thing as a refund, though both are negative rows. A refund
     * is money genuinely returned to a guest; a reversal says "this row was a
     * mistake" and points at the row it corrects. Keeping them distinguishable
     * is what lets a property answer "did we give that money back, or did we
     * never have it?" — which is a different question with a different answer
     * to an accountant.
     *
     * The reversal copies the original's method and collector, because it
     * undoes that movement and not some other one, and its own status is
     * settled immediately: a correction is certain the moment it is made.
     */
    public function reverse(Payment $payment, ?string $reason = null, ?int $recordedBy = null): Payment
    {
        if ($payment->status === PaymentStatus::Failed) {
            throw new InvalidArgumentException(
                'A failed payment never moved any money, so there is nothing to reverse. Leave it as the record that it did not arrive.'
            );
        }

        if ($payment->reversal()->exists()) {
            throw new InvalidArgumentException('That payment has already been reversed.');
        }

        $reservation = $payment->reservation;

        if (! $reservation instanceof Reservation) {
            throw new InvalidArgumentException('That payment is not attached to a stay.');
        }

        return DB::transaction(function () use ($payment, $reservation, $reason, $recordedBy): Payment {
            $reversal = MoneyWriteGuard::allow(fn (): Payment => Payment::create([
                'reservation_id' => $reservation->id,
                'amount' => -$payment->amount,
                'currency' => $payment->currency,
                // The foreign-currency facts are the original's, negated the
                // same way: this undoes that movement, so it undoes the same
                // €300 at the same rate rather than today's.
                'received_amount' => $payment->received_amount === null ? null : -$payment->received_amount,
                'received_currency' => $payment->received_currency,
                'exchange_rate' => $payment->exchange_rate,
                'received_at' => now(),
                'method' => $payment->method,
                'collected_by' => $payment->collected_by,
                'status' => PaymentStatus::Cleared,
                'reference' => $payment->reference,
                'reverses_payment_id' => $payment->id,
                'recorded_by' => $recordedBy,
                'note' => $reason,
            ]));

            $this->refreshFolio($reservation);

            return $reversal;
        });
    }

    /**
     * A fact arriving late: the transfer landed, the capture settled.
     *
     * The one permitted mutation, and it is one-way. A cleared payment does
     * not go back to recorded — if the money turns out not to have arrived,
     * that is a reversal, because something did happen and the record has to
     * say so.
     */
    public function markCleared(Payment $payment): Payment
    {
        return $this->moveStatus($payment, PaymentStatus::Cleared);
    }

    /**
     * It did not arrive: a bounced transfer, a declined capture.
     *
     * The row stays. A payment that was expected and never came is exactly the
     * thing somebody needs to find later, and it stops counting towards the
     * balance the moment it is marked — which is what puts the stay back on
     * the unpaid list where it belongs.
     */
    public function markFailed(Payment $payment): Payment
    {
        return $this->moveStatus($payment, PaymentStatus::Failed);
    }

    private function moveStatus(Payment $payment, PaymentStatus $to): Payment
    {
        if ($payment->status === $to) {
            return $payment;
        }

        if ($payment->status !== PaymentStatus::Recorded) {
            throw new InvalidArgumentException(
                "A [{$payment->status->value}] payment cannot become [{$to->value}]. "
                .'Reverse it instead — the record of what happened stays.'
            );
        }

        $reservation = $payment->reservation;

        return DB::transaction(function () use ($payment, $reservation, $to): Payment {
            MoneyWriteGuard::allow(function () use ($payment, $to): void {
                $payment->status = $to;
                $payment->save();
            });

            if ($reservation instanceof Reservation) {
                $this->refreshFolio($reservation);
            }

            return $payment;
        });
    }

    /**
     * Rewrite what this stay has paid and where that leaves it.
     *
     * Public because a stay's *total* can change after the fact — a price
     * override, a charge added at check-out — and the folio has to follow it.
     * The rest of the class calls it after every write.
     */
    public function refreshFolio(Reservation $reservation): Reservation
    {
        $paidCents = 0;

        foreach ($this->countingPayments($reservation) as $amount) {
            $paidCents += Money::cents($amount);
        }

        $paid = Money::fromCents($paidCents);

        return $this->inventory->recordFolio(
            $reservation,
            $paid,
            self::statusFor($reservation->total_amount, $paid),
        );
    }

    /**
     * Where a stay stands, given what it costs and what has come in.
     *
     * Static and total-in/paid-in rather than reading the reservation, so the
     * rule can be tested on its own and so nothing is tempted to reimplement
     * it beside a screen.
     *
     * The unpriced case is answered deliberately: with no total there is
     * nothing to be square with, so money against it is PartPaid. See
     * FolioStatus.
     */
    public static function statusFor(?float $total, float $paid): FolioStatus
    {
        $paidCents = Money::cents($paid);

        if ($total === null) {
            return $paidCents === 0 ? FolioStatus::Unpaid : FolioStatus::PartPaid;
        }

        $totalCents = Money::cents($total);

        if ($paidCents === $totalCents) {
            return FolioStatus::Paid;
        }

        if ($paidCents > $totalCents) {
            return FolioStatus::Overpaid;
        }

        // Nothing in and nothing owed is settled, not unpaid: a stay
        // comped to zero is square with the world.
        if ($paidCents === 0) {
            return $totalCents === 0 ? FolioStatus::Paid : FolioStatus::Unpaid;
        }

        return FolioStatus::PartPaid;
    }

    /**
     * The amounts that move the balance — everything but the failed ones.
     *
     * Read straight from the database rather than from a loaded relation, so a
     * stale in-memory collection cannot produce a folio total that disagrees
     * with the rows.
     *
     * @return array<int, float>
     */
    private function countingPayments(Reservation $reservation): array
    {
        return Payment::query()
            ->where('reservation_id', $reservation->id)
            ->whereIn('status', array_map(fn (PaymentStatus $status) => $status->value, PaymentStatus::counting()))
            ->pluck('amount')
            ->map(fn (mixed $amount): float => (float) $amount)
            ->all();
    }
}
