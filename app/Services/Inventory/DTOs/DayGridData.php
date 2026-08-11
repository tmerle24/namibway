<?php

namespace App\Services\Inventory\DTOs;

use App\Models\Listing;
use Illuminate\Support\Carbon;

/**
 * One day of a property's timetable: the hour axis down the page, its
 * departures across.
 *
 * The other reading of the same rows — room types down, nights across — is
 * OccupancyGridData. Two shapes, one table; see App\Services\Inventory\DayGrid
 * for why this is not that one transposed.
 *
 * The axis is a **ruler**, not inventory. It carries no counters and is not
 * stored anywhere: the resolution is a property of the screen, and the rows it
 * produces exist so a block can be placed against a time. Putting the grid into
 * the tables is the mistake BOOKING_SYSTEM.md → "Time inside a day" was written
 * to prevent.
 */
class DayGridData
{
    /**
     * @param  array<int, DepartureColumn>  $columns
     */
    public function __construct(
        public readonly Listing $listing,
        public readonly Carbon $date,
        /** First time on the axis, and the zero point every startIndex counts from. */
        public readonly Carbon $axisStart,
        public readonly int $resolutionMinutes,
        public readonly int $rowCount,
        public readonly array $columns,
        public readonly string $currency,
    ) {}

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    /**
     * The axis, one entry per row: the time it starts at, and whether it is
     * worth printing. A label every fifteen minutes is unreadable, so only the
     * hour is named and the rest are ticks.
     *
     * @return array<int, array{time: Carbon, label: string|null}>
     */
    public function axis(): array
    {
        $rows = [];

        for ($index = 0; $index < $this->rowCount; $index++) {
            $time = $this->axisStart->copy()->addMinutes($index * $this->resolutionMinutes);

            $rows[$index] = [
                'time' => $time,
                'label' => $time->minute === 0 ? $time->format('H:i') : null,
            ];
        }

        return $rows;
    }

    public function seatsFree(): int
    {
        return array_sum(array_map(fn (DepartureColumn $column) => $column->seatsFree, $this->columns));
    }

    public function seatsSold(): int
    {
        return array_sum(array_map(fn (DepartureColumn $column) => $column->seatsSold, $this->columns));
    }

    /** Seats the day has across every departure, before anything is sold. */
    public function capacity(): int
    {
        return array_sum(array_map(fn (DepartureColumn $column) => $column->capacity, $this->columns));
    }

    public function occupancyPercent(): int
    {
        $capacity = $this->capacity();

        return $capacity < 1 ? 0 : (int) round($this->seatsSold() / $capacity * 100);
    }
}
