<?php

namespace App\Services\Pricing;

use App\Exceptions\Pricing\UnpriceableStayException;

/**
 * One way of turning a night into an amount.
 *
 * This is the pluggable edge described in BOOKING_SYSTEM.md: a partner with a
 * scheme nobody here has seen is one new class and one new enum case — no
 * migration, no change to the calendar, and no change to how availability
 * works. That is the technical meaning of "we can do that".
 *
 * Two rules hold for every implementation:
 *
 * 1. **Pure.** No queries, no clock, no config. Everything comes from the
 *    context, which is why a table of examples is a real test.
 * 2. **Refuse rather than guess.** A price that cannot be computed throws
 *    UnpriceableStayException naming what is missing. Falling back to
 *    something plausible would sell a stay at a rate nobody set.
 */
interface NightPricer
{
    /**
     * The price of one unit of this room type for this one night.
     *
     * @throws UnpriceableStayException
     */
    public function price(NightPricingContext $context): float;
}
