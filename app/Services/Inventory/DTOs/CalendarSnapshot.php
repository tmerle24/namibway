<?php

namespace App\Services\Inventory\DTOs;

use App\Models\RoomType;
use App\Models\RoomTypeCalendarDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Every calendar night for a set of room types over a date range, read in one
 * query and answered from memory afterwards.
 *
 * This exists because the calendar's per-night reads are per room type: a
 * month across twenty room types asked night by night is six hundred queries,
 * which is exactly what an occupancy grid would do if it asked the obvious
 * way. Nothing here reads the database — App\Services\Inventory\AvailabilityCalendar
 * fills it, and it is a read model, never a cache to be invalidated.
 *
 * The three static rules below are the sparse-calendar contract, and they live
 * here so there is one copy of them: a missing row, or a null override, means
 * "follow the room type". AvailabilityCalendar's own single-night reads call
 * the same three, so the grid and the booking path can never disagree about
 * what a night costs.
 */
class CalendarSnapshot
{
    /**
     * @param  array<int, array<string, RoomTypeCalendarDay>>  $days  room type id => Y-m-d => row
     * @param  array<int, RoomType>  $roomTypes  keyed by id
     */
    public function __construct(
        private readonly array $days,
        private readonly array $roomTypes,
    ) {}

    public function day(int $roomTypeId, CarbonInterface|string $date): ?RoomTypeCalendarDay
    {
        return $this->days[$roomTypeId][$this->key($date)] ?? null;
    }

    public function capacity(int $roomTypeId, CarbonInterface|string $date): int
    {
        $roomType = $this->roomTypes[$roomTypeId] ?? null;

        return $roomType === null ? 0 : self::capacityFor($roomType, $this->day($roomTypeId, $date));
    }

    public function rate(int $roomTypeId, CarbonInterface|string $date): float
    {
        $roomType = $this->roomTypes[$roomTypeId] ?? null;

        return $roomType === null ? 0.0 : self::rateFor($roomType, $this->day($roomTypeId, $date));
    }

    public function sold(int $roomTypeId, CarbonInterface|string $date): int
    {
        $day = $this->day($roomTypeId, $date);

        return $day === null ? 0 : $day->units_sold;
    }

    public function blocked(int $roomTypeId, CarbonInterface|string $date): int
    {
        $day = $this->day($roomTypeId, $date);

        return $day === null ? 0 : $day->units_blocked;
    }

    /**
     * Free units on a night. Can go negative, and deliberately is not clamped:
     * a lodge that lowered total_units below what is already sold has an
     * overbooking, and a screen that quietly showed zero would hide it.
     */
    public function unitsFree(int $roomTypeId, CarbonInterface|string $date): int
    {
        return $this->capacity($roomTypeId, $date)
            - $this->sold($roomTypeId, $date)
            - $this->blocked($roomTypeId, $date);
    }

    /** Whether this room type has any calendar row at all inside the range. */
    public function hasRows(int $roomTypeId): bool
    {
        return ($this->days[$roomTypeId] ?? []) !== [];
    }

    /**
     * Whether anything was said about this room type in the range beyond the
     * defaults — a rate, a capacity override, a restriction, or units taken.
     * A row written by ensureDay() with nothing on it does not count.
     */
    public function hasActivity(int $roomTypeId): bool
    {
        foreach ($this->days[$roomTypeId] ?? [] as $day) {
            if ($day->units_total !== null || $day->rate !== null || $day->min_stay !== null) {
                return true;
            }

            if ($day->closed_to_arrival || $day->closed_to_departure) {
                return true;
            }

            if ($day->units_sold > 0 || $day->units_blocked > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Capacity for a night: the override where there is one, the room type's
     * own units otherwise. Never copied down at booking time — a lodge that
     * raises total_units must see the new number on every night it never
     * explicitly overrode.
     */
    public static function capacityFor(RoomType $roomType, ?RoomTypeCalendarDay $day): int
    {
        if ($day !== null && $day->units_total !== null) {
            return $day->units_total;
        }

        return (int) $roomType->total_units;
    }

    /** Same null-means-fall-back rule as capacity, for the rate. */
    public static function rateFor(RoomType $roomType, ?RoomTypeCalendarDay $day): float
    {
        if ($day !== null && $day->rate !== null) {
            return $day->rate;
        }

        return (float) $roomType->rate_per_night;
    }

    /** Units a night is holding, sold and blocked together. */
    public static function occupiedOn(?RoomTypeCalendarDay $day): int
    {
        if ($day === null) {
            return 0;
        }

        return $day->units_sold + $day->units_blocked;
    }

    private function key(CarbonInterface|string $date): string
    {
        return is_string($date) ? $date : Carbon::parse($date)->toDateString();
    }
}
