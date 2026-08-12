<?php

namespace App\Filament\Partner\Support;

use App\Enums\CalendarRange;
use App\Models\Listing;
use App\Models\User;
use App\Services\Inventory\DayGrid;
use Illuminate\Support\Facades\Auth;

/**
 * How this operator reads their calendar, kept between visits.
 *
 * A wall planner is set up once and then lived in. Somebody who works the week
 * view works it every morning, and making them press "Week" every morning is
 * the sort of small friction that makes a screen feel like it was built for
 * somebody else. So the *reading* is remembered: the range, how finely the day
 * is drawn, and which product's rates the cells show.
 *
 * Three rules hold the shape:
 *
 * - **The date is never remembered.** Opening the calendar lands on today, the
 *   way opening a diary does. A range is how you read the book; a date is where
 *   you happen to be in it, and a lodge coming back on Monday wants this week,
 *   not the September they were checking rates for.
 * - **A link never rewrites a preference.** The query string still wins for the
 *   page it addresses — that is what makes a calendar pastable to a colleague —
 *   but opening somebody else's link leaves your own calendar as you set it.
 *   Only pressing a control on the toolbar changes what is remembered.
 * - **The rate plan is per property.** Plans belong to a property, so one
 *   remembered id could not mean anything on the next lodge. Everything else is
 *   the operator's habit and travels with them.
 *
 * On the user rather than the session (see User::rememberPreference): a session
 * that expires overnight would remember nothing that matters.
 */
class CalendarPreferences
{
    private const RANGE = 'partner.calendar.range';

    private const RESOLUTION = 'partner.calendar.resolution';

    private const RATE_PLAN = 'partner.calendar.rate_plan';

    /** The range to open on, or null where this operator has never chosen one. */
    public function range(): ?CalendarRange
    {
        $stored = $this->user()?->preference(self::RANGE);

        return is_string($stored) ? CalendarRange::tryFrom($stored) : null;
    }

    public function rememberRange(CalendarRange $range): void
    {
        $this->user()?->rememberPreference(self::RANGE, $range->value);
    }

    /**
     * Minutes per row on the hour axis. Null means the day view works it out
     * from the timetable, which is the normal state — see DayGrid::resolution().
     */
    public function resolution(): ?int
    {
        $stored = $this->user()?->preference(self::RESOLUTION);

        return is_int($stored) && in_array($stored, DayGrid::RESOLUTIONS, true) ? $stored : null;
    }

    public function rememberResolution(?int $minutes): void
    {
        $this->user()?->rememberPreference(
            self::RESOLUTION,
            $minutes !== null && in_array($minutes, DayGrid::RESOLUTIONS, true) ? $minutes : null,
        );
    }

    /**
     * The rate plan last looked at on this property. Never trusted on its own:
     * the caller resolves it against the property's own plans, so a stale id —
     * a plan since deleted, or one belonging to a lodge this account no longer
     * operates — falls back to the default rather than showing nothing.
     */
    public function ratePlanId(Listing $property): ?int
    {
        $stored = $this->user()?->preference(self::RATE_PLAN.'.'.$property->id);

        return is_int($stored) ? $stored : null;
    }

    public function rememberRatePlan(Listing $property, ?int $ratePlanId): void
    {
        $this->user()?->rememberPreference(self::RATE_PLAN.'.'.$property->id, $ratePlanId);
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
