<?php

namespace App\Services\Payments\DTOs;

use App\Enums\FolioStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationCharge;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * A stay read as a statement rather than as three columns.
 *
 * Nothing new is stored for this — the reservation *is* the folio (see
 * PAYMENTS_BUILD.md slice 1, and note that split folios are a real future PMS
 * feature and deliberately not built). This assembles what is already there
 * into the order somebody reads it in: what the stay came to, what was added
 * on top, what came off, what has been paid, what is left.
 *
 * Read-only. It computes nothing the recorder has already decided: `paid` and
 * `status` come off the reservation, so this view and the arrivals board
 * cannot disagree.
 */
class FolioStatement
{
    /**
     * @param  Collection<int, ReservationCharge>  $charges
     * @param  Collection<int, Payment>  $payments
     */
    public function __construct(
        public readonly Reservation $reservation,
        public readonly Collection $charges,
        public readonly Collection $payments,
    ) {}

    public function currency(): string
    {
        return $this->reservation->currency;
    }

    /** What the nights came to, before anything was added or taken off. */
    public function stayAmount(): float
    {
        return $this->reservation->stayAmount();
    }

    public function discount(): float
    {
        return (float) ($this->reservation->discount_amount ?? 0.0);
    }

    /** What the guest owes in total. Null for a stay nobody has priced yet. */
    public function total(): ?float
    {
        return $this->reservation->total_amount;
    }

    /** What has come in — recorded and cleared alike, reversals netted off. */
    public function paid(): float
    {
        return (float) $this->reservation->paid_amount;
    }

    /**
     * The part of that which is certain.
     *
     * Shown beside the paid total rather than instead of it: a property
     * chasing money needs to know that N$ 4,000 of the N$ 9,000 is still only
     * somebody's word about a bank transfer.
     */
    public function cleared(): float
    {
        $cents = 0;

        foreach ($this->payments as $payment) {
            if ($payment->status->countsTowardsBalance() && $payment->status->isCertain()) {
                $cents += Money::cents($payment->amount);
            }
        }

        return Money::fromCents($cents);
    }

    /**
     * What is still owed. Negative means the property owes the guest.
     *
     * A stay with no price yet has no balance — not zero, which would read as
     * "square". Zero and unknown are different answers and a screen has to be
     * able to tell them apart.
     */
    public function balance(): ?float
    {
        $total = $this->total();

        if ($total === null) {
            return null;
        }

        return Money::fromCents(Money::cents($total) - Money::cents($this->paid()));
    }

    public function status(): FolioStatus
    {
        return $this->reservation->payment_status;
    }

    /**
     * Whether there is anything to show at all.
     *
     * A stay with no price and no payments has no statement worth printing,
     * and an empty table of zeroes reads as a bug rather than as "nothing has
     * happened yet".
     */
    public function isEmpty(): bool
    {
        return $this->total() === null && $this->payments->isEmpty();
    }
}
