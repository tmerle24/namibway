<?php

namespace App\Services\Inventory\DTOs;

use App\Models\BookableUnit;
use App\Models\BookableUnitCalendarDay;
use App\Models\RatePlanDay;
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
 * The static rules below are the sparse-calendar contract, and they live here
 * so there is one copy of them: a missing row, or a null override, means
 * "follow the room type". AvailabilityCalendar's own single-night reads call
 * the same ones, so the grid and the booking path can never disagree about
 * what a night costs.
 *
 * It reads from **two** sparse calendars, because a room is sold once however
 * many rate plans it is offered under: inventory keyed by room type, rates and
 * restrictions keyed by rate plan as well. See BOOKING_SYSTEM.md. A snapshot
 * is always taken for one rate plan; a property with none passes null and gets
 * the room types' own rates, exactly as before rate plans existed.
 */
class CalendarSnapshot
{
    /**
     * Departures are kept apart from the day, in their own two arrays, for the
     * reason the migration gives: a row keyed to a slot counts seats on one
     * tour and a row keyed to nothing counts the property's units, and a
     * reader that mixed them would answer "how full is this day" with the
     * 14:00 tour's number. Keyed by slot inside the date, because the whole
     * point of a slot being a row is that the 09:00 departure selling out says
     * nothing about the 14:00 one.
     *
     * @param  array<int, array<string, BookableUnitCalendarDay>>  $days  room type id => Y-m-d => inventory row
     * @param  array<int, BookableUnit>  $bookableUnits  keyed by id
     * @param  array<int, array<string, RatePlanDay>>  $rateDays  room type id => Y-m-d => rate row
     * @param  array<int, array<string, array<int, BookableUnitCalendarDay>>>  $slotDays  room type id => Y-m-d => slot id => inventory row
     * @param  array<int, array<string, array<int, RatePlanDay>>>  $slotRateDays  room type id => Y-m-d => slot id => rate row
     */
    public function __construct(
        private readonly array $days,
        private readonly array $bookableUnits,
        private readonly array $rateDays = [],
        private readonly array $slotDays = [],
        private readonly array $slotRateDays = [],
    ) {}

    public function day(int $bookableUnitId, CarbonInterface|string $date): ?BookableUnitCalendarDay
    {
        return $this->days[$bookableUnitId][$this->key($date)] ?? null;
    }

    /** The rate plan's row for a night, if the plan said anything about it. */
    public function rateDay(int $bookableUnitId, CarbonInterface|string $date): ?RatePlanDay
    {
        return $this->rateDays[$bookableUnitId][$this->key($date)] ?? null;
    }

    /**
     * One departure's inventory row on a date, if it has one.
     *
     * A sparse calendar means the same thing here as it does for a night: no
     * row is "as many seats as the unit has", not "none".
     */
    public function slotDay(int $bookableUnitId, CarbonInterface|string $date, int $slotId): ?BookableUnitCalendarDay
    {
        return $this->slotDays[$bookableUnitId][$this->key($date)][$slotId] ?? null;
    }

    /** One departure's own rate row, where the plan set a price for it. */
    public function slotRateDay(int $bookableUnitId, CarbonInterface|string $date, int $slotId): ?RatePlanDay
    {
        return $this->slotRateDays[$bookableUnitId][$this->key($date)][$slotId] ?? null;
    }

    public function capacity(int $bookableUnitId, CarbonInterface|string $date): int
    {
        $bookableUnit = $this->bookableUnits[$bookableUnitId] ?? null;

        return $bookableUnit === null ? 0 : self::capacityFor($bookableUnit, $this->day($bookableUnitId, $date));
    }

    public function rate(int $bookableUnitId, CarbonInterface|string $date): float
    {
        $bookableUnit = $this->bookableUnits[$bookableUnitId] ?? null;

        return $bookableUnit === null ? 0.0 : self::rateFor($bookableUnit, $this->rateDay($bookableUnitId, $date));
    }

    public function sold(int $bookableUnitId, CarbonInterface|string $date): int
    {
        $day = $this->day($bookableUnitId, $date);

        return $day === null ? 0 : $day->units_sold;
    }

    public function blocked(int $bookableUnitId, CarbonInterface|string $date): int
    {
        $day = $this->day($bookableUnitId, $date);

        return $day === null ? 0 : $day->units_blocked;
    }

    /**
     * Free units on a night. Can go negative, and deliberately is not clamped:
     * a lodge that lowered total_units below what is already sold has an
     * overbooking, and a screen that quietly showed zero would hide it.
     */
    public function unitsFree(int $bookableUnitId, CarbonInterface|string $date): int
    {
        return $this->capacity($bookableUnitId, $date)
            - $this->sold($bookableUnitId, $date)
            - $this->blocked($bookableUnitId, $date);
    }

    /** Whether this room type has any calendar row at all inside the range. */
    public function hasRows(int $bookableUnitId): bool
    {
        return ($this->days[$bookableUnitId] ?? []) !== [];
    }

    /**
     * Whether anything was said about this room type in the range beyond the
     * defaults — a rate, a capacity override, a restriction, or units taken.
     * A row written by ensureDay() with nothing on it does not count.
     */
    public function hasActivity(int $bookableUnitId): bool
    {
        foreach ($this->days[$bookableUnitId] ?? [] as $day) {
            if ($day->units_total !== null || $day->units_sold > 0 || $day->units_blocked > 0) {
                return true;
            }
        }

        foreach ($this->rateDays[$bookableUnitId] ?? [] as $day) {
            if ($day->rate !== null || $day->min_stay !== null) {
                return true;
            }

            if ($day->closed_to_arrival || $day->closed_to_departure) {
                return true;
            }
        }

        // Seats sold on a departure count too: a retired unit is hidden as
        // noise, and hiding a booking is not what that is for.
        foreach ($this->slotDays[$bookableUnitId] ?? [] as $slots) {
            foreach ($slots as $day) {
                if ($day->units_total !== null || $day->units_sold > 0 || $day->units_blocked > 0) {
                    return true;
                }
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
    public static function capacityFor(BookableUnit $bookableUnit, ?BookableUnitCalendarDay $day): int
    {
        if ($day !== null && $day->units_total !== null) {
            return $day->units_total;
        }

        return (int) $bookableUnit->total_units;
    }

    /**
     * Same null-means-fall-back rule as capacity, for the rate — but read from
     * the rate plan's calendar. A property with no rate plan, or a plan that
     * has said nothing about this night, charges what the room type charges.
     */
    public static function rateFor(BookableUnit $bookableUnit, ?RatePlanDay $day): float
    {
        if ($day !== null && $day->rate !== null) {
            return $day->rate;
        }

        return (float) $bookableUnit->base_rate;
    }

    /** Units a night is holding, sold and blocked together. */
    public static function occupiedOn(?BookableUnitCalendarDay $day): int
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
