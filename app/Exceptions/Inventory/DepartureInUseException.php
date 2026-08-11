<?php

namespace App\Exceptions\Inventory;

use RuntimeException;

/**
 * A departure with seats on it cannot be deleted.
 *
 * The reason is a foreign key rather than a policy. `bookable_unit_calendar_days.slot_id`
 * and `rate_plan_days.slot_id` both cascade on delete, so removing a departure
 * takes its counters with it — silently, below Eloquent, past the write guard,
 * and without anything to restore them from. A property that has stopped
 * running the 09:00 tour wants it off the timetable, not erased from the
 * seasons it already sold.
 */
class DepartureInUseException extends RuntimeException
{
    public function __construct(string $label, int $seats)
    {
        parent::__construct(
            "\"{$label}\" has {$seats} seat(s) on it. Switch it off instead of deleting it — "
            .'deleting a departure would take the bookings and the prices already on it.'
        );
    }
}
