<?php

namespace App\Services\Inventory\DTOs;

use Illuminate\Support\Carbon;

/**
 * One date column of the occupancy grid, with the things the header needs to
 * decide without doing date arithmetic in a Blade loop.
 */
class OccupancyColumn
{
    public function __construct(
        public readonly Carbon $date,
        public readonly bool $isToday,
        public readonly bool $isWeekend,
        public readonly bool $isPast,
        /** First column of its month — where the header prints the month name. */
        public readonly bool $startsMonth,
        /** Rooms free across every room type shown, on this night. */
        public readonly int $unitsFree,
        public readonly int $capacity,
        public readonly int $unitsSold,
        public readonly int $unitsBlocked,
    ) {}

    /** Sold units as a share of capacity — blocked rooms are not sales. */
    public function occupancyPercent(): int
    {
        if ($this->capacity < 1) {
            return 0;
        }

        return (int) round($this->unitsSold / $this->capacity * 100);
    }
}
