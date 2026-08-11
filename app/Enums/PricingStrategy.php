<?php

namespace App\Enums;

/**
 * How a rate plan turns a night into an amount.
 *
 * This is the pluggable edge of the system, and the reason it is an enum
 * naming a *function* rather than a set of columns: a partner whose scheme we
 * have not seen is one new case and one new class, with no migration, no
 * change to the calendar and no change to availability. See
 * BOOKING_SYSTEM.md.
 *
 * The set stays closed and small on purpose. "Open to everything" means the
 * architecture takes a new strategy cheaply — not that every conceivable
 * option ships before a partner has asked for one.
 *
 * Only PerUnit is implemented today; the calendar rate is the whole price of
 * the room for the night, which is exactly what the system did before rate
 * plans existed. The others are declared so the column that stores them has
 * its full vocabulary from the start, and are rejected until they are built —
 * a strategy that silently fell back to per-unit would price a stay wrongly
 * and say nothing.
 */
enum PricingStrategy: string
{
    /** One amount for the room, however many people are in it. */
    case PerUnit = 'per_unit';

    /** An amount per number of guests: 1, 2, 3, 4 … */
    case PerOccupancy = 'per_occupancy';

    /** An amount per person sharing, plus a single supplement. */
    case PerPersonSharing = 'per_person_sharing';

    public function label(): string
    {
        return match ($this) {
            self::PerUnit => 'Per room',
            self::PerOccupancy => 'Per number of guests',
            self::PerPersonSharing => 'Per person sharing',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PerUnit => 'One price for the room per night, whoever is in it. Self-catering units, guest farms and campsites are usually sold this way.',
            self::PerOccupancy => 'A price per night for one guest, for two, for three — the room costs more as it fills up.',
            self::PerPersonSharing => 'A price per person per night sharing a room, plus a supplement for somebody travelling alone. How most Namibian lodges and safari camps quote.',
        };
    }

    /**
     * Whether the calculation behind this strategy exists yet.
     *
     * @see BOOKING_SYSTEM.md, "Order of work" — occupancy and per-person
     *      pricing arrive in step 2, together with guest categories.
     */
    public function isImplemented(): bool
    {
        return $this === self::PerUnit;
    }

    /** @return array<int, self> */
    public static function implemented(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => $case->isImplemented()));
    }
}
