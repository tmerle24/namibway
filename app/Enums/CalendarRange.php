<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * How much of the book is on screen: a day, a week, or a month.
 *
 * Ranges **snap**. A month view runs from the 1st to the last, a week from
 * Monday to Sunday, and the arrows move a whole one at a time. The alternative
 * — a rolling thirty days from wherever you happen to be — was what this screen
 * did before, and it cannot survive a jump control: a range labelled "September"
 * that runs 12 September to 11 October is a lie, and a lodge comparing two
 * Septembers would be comparing two different windows.
 *
 * The cost is real and worth naming: opening the calendar on the 28th shows
 * three days ahead. That is what the week view and one press of "Later" are
 * for, and it is the trade every wall planner already makes.
 */
enum CalendarRange: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /**
     * A range from the query string. Anything unrecognised is a month, because
     * a mistyped URL should show the calendar rather than an error page — the
     * same rule the `from` parameter already follows.
     */
    public static function parse(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Month;
    }

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Day',
            self::Week => 'Week',
            self::Month => 'Month',
        };
    }

    public function isDay(): bool
    {
        return $this === self::Day;
    }

    /** The first day on screen for a date somewhere inside the range. */
    public function start(CarbonInterface $date): Carbon
    {
        $date = Carbon::parse($date)->startOfDay();

        return match ($this) {
            self::Day => $date,
            // Monday, which is how a Namibian week is read and how Carbon
            // reckons it by default. A week starting on the day you opened the
            // page would put the weekend in the middle of some views and the
            // ends of others.
            self::Week => $date->copy()->startOfWeek(),
            self::Month => $date->copy()->startOfMonth(),
        };
    }

    /**
     * How many columns the range covers. February is 28 or 29 — which is the
     * reason this takes the start date rather than returning a constant.
     */
    public function days(CarbonInterface $start): int
    {
        return match ($this) {
            self::Day => 1,
            self::Week => 7,
            self::Month => (int) Carbon::parse($start)->daysInMonth,
        };
    }

    /**
     * The range before or after this one. `$direction` is in whole ranges, not
     * days: an arrow that moved a fixed fortnight would leave a month view
     * straddling two months, which is exactly what snapping exists to avoid.
     */
    public function shift(CarbonInterface $start, int $direction): Carbon
    {
        $start = Carbon::parse($start)->startOfDay();

        $moved = match ($this) {
            self::Day => $start->copy()->addDays($direction),
            self::Week => $start->copy()->addWeeks($direction),
            self::Month => $start->copy()->addMonthsNoOverflow($direction),
        };

        return $this->start($moved);
    }

    /** "September 2026", "Mon 14 – Sun 20 Sep", "Tuesday 15 September 2026". */
    public function describe(CarbonInterface $start): string
    {
        $start = Carbon::parse($start)->startOfDay();

        return match ($this) {
            self::Day => $start->isoFormat('dddd D MMMM YYYY'),
            self::Week => $start->isoFormat('D MMM').' – '.$start->copy()->addDays(6)->isoFormat('D MMM YYYY'),
            self::Month => $start->isoFormat('MMMM YYYY'),
        };
    }
}
