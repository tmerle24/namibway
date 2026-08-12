<?php

namespace App\Enums;

/**
 * What a partner bought: a booking system, or a booking system that is also
 * the front desk.
 *
 * ## The one rule that keeps this from becoming a second product
 *
 * **The mode decides what a partner sees, never how anything is recorded.**
 * A booking-only property still has a folio, still has payment records, still
 * has an invoice — it simply has no screens for posting a bar bill to a room.
 *
 * The moment the mode changes what is *stored*, upgrading a partner from
 * booking-only to full becomes a migration, and two partners on the same
 * server stop being comparable — which is exactly the "two systems" problem
 * this whole workstream exists to avoid, reintroduced from the inside. So this
 * enum is read by the partner panel's navigation and by nothing in
 * `app/Services`, and there is a test that says so.
 */
enum OperatingMode: string
{
    /**
     * Sell the room, take the request, keep the calendar. What every partner
     * has today, and what a lodge on somebody else's PMS would buy.
     */
    case BookingOnly = 'booking_only';

    /**
     * Additionally the desk: moving a guest through their day, room-level
     * assignment, extras posted to the folio, housekeeping, day-end
     * reporting — as those exist.
     */
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::BookingOnly => 'Booking system',
            self::Full => 'Booking system and front desk',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BookingOnly => 'Rates, availability, bookings and the money side. The property runs its front desk somewhere else.',
            self::Full => 'Everything above, plus the desk: arrivals, check-in and check-out.',
        };
    }

    /** Whether this partner's staff check guests in and out on our screens. */
    public function runsFrontDesk(): bool
    {
        return $this === self::Full;
    }
}
