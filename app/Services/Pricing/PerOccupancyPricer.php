<?php

namespace App\Services\Pricing;

use App\Exceptions\Pricing\UnpriceableStayException;

/**
 * A price per number of guests: 1000 for one, 1300 for two, 1500 for three.
 *
 * The room costs more as it fills up, which is how a guesthouse or a lodge
 * with fixed unit tariffs quotes. This is OTA's `BaseByGuestAmt` and nothing
 * more — the amounts are looked up, not derived.
 *
 * Two fallbacks, and the difference between them matters:
 *
 * - **A night with no amounts at all** falls back to the plan's own rate for
 *   the night. That is the sparse-calendar rule the whole system runs on, and
 *   it is what lets a property switch to this strategy and fill the numbers in
 *   afterwards without its calendar going blank.
 * - **A night with amounts, but not for this many guests**, is refused. One
 *   and two guests being priced says nothing about four, and selling four at
 *   the two-guest price is the kind of quiet mistake that is only found at
 *   check-out.
 */
final class PerOccupancyPricer implements NightPricer
{
    public function price(NightPricingContext $context): float
    {
        $guests = $context->occupancy->occupancyCount($context->categories);

        if ($guests < 1) {
            throw UnpriceableStayException::occupancyRequired(
                $context->bookableUnit->name,
                $context->planLabel(),
            );
        }

        if ($context->guestAmounts === []) {
            return round($context->baseRate, 2);
        }

        $amount = $context->guestAmounts[$guests] ?? null;

        if ($amount === null) {
            throw UnpriceableStayException::noAmountForGuestCount(
                $context->bookableUnit->name,
                $context->planLabel(),
                $guests,
                $context->dateLabel(),
            );
        }

        return round($amount, 2);
    }
}
