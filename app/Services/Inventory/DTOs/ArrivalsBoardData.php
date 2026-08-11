<?php

namespace App\Services\Inventory\DTOs;

use App\Models\Listing;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * One day at the front desk: who arrives, who leaves, who is staying on.
 */
class ArrivalsBoardData
{
    /**
     * @param  Collection<int, Reservation>  $arrivals
     * @param  Collection<int, Reservation>  $departures
     * @param  Collection<int, Reservation>  $inHouse
     */
    public function __construct(
        public readonly Listing $listing,
        public readonly Carbon $date,
        public readonly Carbon $today,
        public readonly Collection $arrivals,
        public readonly Collection $departures,
        public readonly Collection $inHouse,
    ) {}

    public function isEmpty(): bool
    {
        return $this->arrivals->isEmpty() && $this->departures->isEmpty() && $this->inHouse->isEmpty();
    }

    /** Rooms occupied tonight: everyone arriving plus everyone staying on. */
    public function unitsStayingTonight(): int
    {
        return $this->units($this->arrivals) + $this->units($this->inHouse);
    }

    public function guestsStayingTonight(): int
    {
        return $this->guests($this->arrivals) + $this->guests($this->inHouse);
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     */
    public function units(Collection $reservations): int
    {
        return (int) $reservations->sum(fn (Reservation $reservation) => $reservation->units->sum('quantity'));
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     */
    public function guests(Collection $reservations): int
    {
        return (int) $reservations->sum(fn (Reservation $reservation) => $reservation->adults + $reservation->children);
    }
}
