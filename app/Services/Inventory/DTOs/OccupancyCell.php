<?php

namespace App\Services\Inventory\DTOs;

use Illuminate\Support\Carbon;

/**
 * One room type on one night: what is free, what it costs, and what the
 * restrictions say. Everything is already resolved against the room type's
 * defaults, so a view never has to know that the calendar is sparse.
 */
class OccupancyCell
{
    public function __construct(
        public readonly Carbon $date,
        public readonly int $capacity,
        public readonly int $unitsSold,
        public readonly int $unitsBlocked,
        public readonly int $unitsFree,
        public readonly float $rate,
        public readonly ?int $minStay,
        public readonly bool $closedToArrival,
        public readonly bool $closedToDeparture,
        /**
         * How many departures this unit runs on this date — zero for
         * everything sold by the night, which is every property today.
         *
         * Where it is not zero the counters above are seats summed across the
         * day's departures, and the rate is the cheapest of them. That is the
         * only honest thing a month view can say about a timetable: the detail
         * of which departure sold what is the day view's job, and drawing it
         * here would be a row hundreds of lanes tall.
         */
        public readonly int $departures = 0,
    ) {}

    /** Whether this cell counts seats on departures rather than units on a night. */
    public function isDepartureDay(): bool
    {
        return $this->departures > 0;
    }

    public function isSoldOut(): bool
    {
        return $this->unitsFree === 0;
    }

    /**
     * More units are held than exist. Only reachable by lowering capacity
     * under stays that are already in the book — which is precisely why the
     * grid shows it instead of clamping to zero.
     */
    public function isOverbooked(): bool
    {
        return $this->unitsFree < 0;
    }

    public function hasRestriction(): bool
    {
        return $this->closedToArrival || $this->closedToDeparture || ($this->minStay !== null && $this->minStay > 1);
    }
}
