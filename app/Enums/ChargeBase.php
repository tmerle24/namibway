<?php

namespace App\Enums;

/**
 * What a percentage charge is a percentage *of*.
 *
 * Two options, and the second one exists for a real case rather than for
 * generality: where a lodge passes the tourism levy on to the guest, VAT is
 * charged on the levied amount, not on the room rate alone. Expressing that as
 * "VAT applies to everything before it" keeps both charges independent — the
 * levy does not have to know VAT exists, and the order is the sort order the
 * lodge already sees on screen.
 *
 * Everything defaults to StayOnly, which is the answer for a property with one
 * tax and nothing else.
 */
enum ChargeBase: string
{
    /** The stay, after any discount. */
    case StayOnly = 'stay_only';

    /** The stay plus every charge sorted before this one. */
    case StayPlusPreceding = 'stay_plus_preceding';

    public function label(): string
    {
        return match ($this) {
            self::StayOnly => 'The stay',
            self::StayPlusPreceding => 'The stay plus the charges above',
        };
    }
}
