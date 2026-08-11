<?php

namespace App\Exceptions\Pricing;

use RuntimeException;

/**
 * The stay cannot be priced, and inventing a number would be worse than
 * refusing.
 *
 * Kept apart from the inventory exceptions on purpose: those two say "there is
 * no room" and "there is a room but not on those terms". This one says the
 * room is free and the price is unknown, which is a different sentence at the
 * desk and a different fix — somebody has to enter a rate.
 *
 * Every message names what is missing and for which night, because "cannot be
 * priced" is not something a receptionist can act on.
 */
class UnpriceableStayException extends RuntimeException
{
    public static function occupancyRequired(string $bookableUnit, string $ratePlan): self
    {
        return new self(
            "{$ratePlan} prices by the number of guests, so the booking has to say who is in the {$bookableUnit}."
        );
    }

    public static function noAmountForGuestCount(string $bookableUnit, string $ratePlan, int $guests, string $date): self
    {
        return new self(
            "{$bookableUnit} has no price for {$guests} guests on {$date} under {$ratePlan}."
        );
    }

    public static function tooManyGuests(string $bookableUnit, int $guests, int $capacity): self
    {
        return new self(
            "{$bookableUnit} sleeps {$capacity}; {$guests} guests were entered."
        );
    }
}
