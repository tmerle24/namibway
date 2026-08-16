<?php

namespace App\Exceptions\Booking;

use App\Models\Inquiry;
use RuntimeException;

/**
 * A confirmed request that cannot be turned into a stay on the calendar.
 *
 * Always an operational problem rather than a guest-facing one: the booking
 * itself stands, and this says the calendar could not be made to agree with
 * it. Never swallow one — see StayPromoter for who is told.
 */
class StayNotPromotableException extends RuntimeException
{
    /**
     * Not a failure at all, and the one case `StayPromoter` must not raise an
     * alert for: a table booking and an order are real requests that a partner
     * answers, and neither of them allocates nights on a calendar.
     */
    public static function notAStay(Inquiry $inquiry): self
    {
        return new self(
            "Request #{$inquiry->id} is a ".$inquiry->kind->getLabel().', which does not '
            .'become a stay on the calendar.'
        );
    }

    public static function withoutDates(Inquiry $inquiry): self
    {
        return new self(
            "Request #{$inquiry->id} has no check-in and check-out dates, so there are no nights to "
            .'allocate. Dates were only ever free text on this request ("'.($inquiry->travel_dates ?? '—').'").'
        );
    }

    public static function withoutBookableUnit(Inquiry $inquiry): self
    {
        return new self(
            "Request #{$inquiry->id} does not say which room type it is for, and the property has more "
            .'than one, so nothing can be allocated without guessing.'
        );
    }

    public static function withoutProperty(Inquiry $inquiry): self
    {
        return new self("Request #{$inquiry->id} has no property to allocate against.");
    }
}
