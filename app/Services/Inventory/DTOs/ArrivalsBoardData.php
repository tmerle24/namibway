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

    /**
     * Rooms on each board basis tonight — the kitchen's question, and the
     * reason board is on this screen at all.
     *
     * Counted in **rooms and not in guests**, which is the honest number: board
     * is a property of a room line, and a stay holding one DBB room and one
     * B&B room cannot have its head count split between them without inventing
     * an attribution. The guest total beside it is the other half of the
     * answer, and between the two a kitchen can lay the tables.
     *
     * Rooms sold with no board stated are left out rather than counted as room
     * only: not saying is not the same as saying no meals.
     *
     * @return array<string, int> board label => rooms
     */
    public function boardTonight(): array
    {
        $counts = [];

        foreach ([$this->arrivals, $this->inHouse] as $reservations) {
            foreach ($reservations as $reservation) {
                foreach ($reservation->units as $unit) {
                    $board = $unit->board_basis;

                    if ($board === null) {
                        continue;
                    }

                    $counts[$board->shortLabel()] = ($counts[$board->shortLabel()] ?? 0) + $unit->quantity;
                }
            }
        }

        ksort($counts);

        return $counts;
    }
}
