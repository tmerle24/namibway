<?php

namespace App\Services\Inventory\DTOs;

use App\Models\Listing;
use Illuminate\Support\Carbon;

/**
 * The whole occupancy screen as data. Built by
 * App\Services\Inventory\OccupancyGrid in a fixed number of queries and then
 * rendered without touching the database again — which is the point of
 * splitting the two: the query can be tuned without opening the view, and the
 * view can be rearranged without risking a query per cell.
 */
class OccupancyGridData
{
    /**
     * @param  array<int, OccupancyColumn>  $columns
     * @param  array<int, OccupancyRow>  $rows
     */
    public function __construct(
        public readonly Listing $listing,
        public readonly array $columns,
        public readonly array $rows,
        public readonly Carbon $from,
        /** Exclusive: the day after the last column. */
        public readonly Carbon $to,
        public readonly Carbon $today,
        public readonly string $currency,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    /** Sold units over capacity across the whole visible range. */
    public function occupancyPercent(): int
    {
        $capacity = 0;
        $sold = 0;

        foreach ($this->columns as $column) {
            $capacity += $column->capacity;
            $sold += $column->unitsSold;
        }

        return $capacity < 1 ? 0 : (int) round($sold / $capacity * 100);
    }
}
