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
    ) {}

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
