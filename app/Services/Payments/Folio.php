<?php

namespace App\Services\Payments;

use App\Enums\FolioStatus;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Payments\DTOs\FolioStatement;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reading the money side. **Read only** — it never calls PaymentRecorder, the
 * same arrangement ArrivalsBoard has with InventoryWriter.
 *
 * A cancelled stay still has a folio and still has a balance, which is exactly
 * the case a system without one gets wrong: a forfeited deposit on a stay
 * nobody is coming for is money the property has and the guest has lost, and
 * both sides need to be able to see it. So nothing here filters on status.
 */
class Folio
{
    public function for(Reservation $reservation): FolioStatement
    {
        return new FolioStatement(
            reservation: $reservation,
            charges: $reservation->charges()->get(),
            payments: $this->paymentsFor($reservation),
        );
    }

    /**
     * The stays of one property that are not square, soonest arrival first.
     *
     * Ordered by arrival rather than by amount because that is the order the
     * work happens in: money is collected when a guest is standing there.
     *
     * @return Collection<int, Reservation>
     */
    public function outstandingFor(Listing $listing): Collection
    {
        return Reservation::query()
            ->where('listing_id', $listing->id)
            ->whereIn(
                'payment_status',
                array_map(fn (FolioStatus $status) => $status->value, FolioStatus::outstanding()),
            )
            // A stay with no price yet is on this list on purpose — see
            // FolioStatus. Somebody has to price it before anybody can be
            // asked to pay, and that is the same job.
            ->orderBy('check_in')
            ->orderBy('guest_name')
            ->with(['units.bookableUnit'])
            ->get();
    }

    /**
     * Oldest first: a statement is read in the order the money moved, and a
     * reversal that appeared above the payment it takes back would be
     * unreadable.
     *
     * @return Collection<int, Payment>
     */
    private function paymentsFor(Reservation $reservation): Collection
    {
        return Payment::query()
            ->where('reservation_id', $reservation->id)
            ->orderBy('received_at')
            ->orderBy('id')
            ->with('reverses')
            ->get();
    }
}
