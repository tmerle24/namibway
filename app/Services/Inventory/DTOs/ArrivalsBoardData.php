<?php

namespace App\Services\Inventory\DTOs;

use App\Models\Listing;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * One day at the front desk: who arrives, who leaves, who is staying on — and,
 * where the property runs departures, who is on each of them.
 *
 * The manifests are a DayGridData: the calendar's own reading of the same day,
 * presented as passenger lists rather than as blocks on an hour axis. Null for
 * a property that sells only nights, which is what keeps every departure-shaped
 * thing off a lodge's screen.
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
        public readonly ?DayGridData $manifests = null,
        /** Whether anything here is sold by the night. False for a tour operator. */
        public readonly bool $sellsNights = true,
    ) {}

    public function isEmpty(): bool
    {
        return $this->arrivals->isEmpty()
            && $this->departures->isEmpty()
            && $this->inHouse->isEmpty()
            && ! $this->hasManifests();
    }

    /** Whether anything departs on this date. False for every lodge. */
    public function hasManifests(): bool
    {
        return $this->manifests !== null && ! $this->manifests->isEmpty();
    }

    /**
     * Departures that actually have somebody on them. A timetable entry
     * nobody booked is on the calendar, where an empty seat count is the
     * point; on a passenger list it is a page of nothing.
     *
     * @return array<int, DepartureColumn>
     */
    public function runningToday(): array
    {
        if ($this->manifests === null) {
            return [];
        }

        return array_values(array_filter(
            $this->manifests->columns,
            fn (DepartureColumn $column) => $column->stays !== [],
        ));
    }

    /** Seats sold across every departure today. */
    public function seatsToday(): int
    {
        return $this->manifests?->seatsSold() ?? 0;
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
     * Rooms held by these stays.
     *
     * Seat lines are left out: a mixed booking of one chalet and three places
     * on the morning ride holds one room, and counting four would put a lodge
     * four beds short on a list it lays tables from.
     *
     * @param  Collection<int, Reservation>  $reservations
     */
    public function units(Collection $reservations): int
    {
        return (int) $reservations->sum(
            fn (Reservation $reservation) => $reservation->units
                ->filter(fn (ReservationUnit $unit) => $unit->slot_id === null)
                ->sum('quantity')
        );
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
