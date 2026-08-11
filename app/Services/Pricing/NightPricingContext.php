<?php

namespace App\Services\Pricing;

use App\Models\BookableUnit;
use App\Models\GuestCategory;
use App\Models\RatePlan;
use Illuminate\Support\Carbon;

/**
 * Everything one night's price is computed from, gathered before the
 * calculation starts.
 *
 * The point of passing a context rather than letting the pricers query is that
 * a pricer is then a pure function — same input, same number, no database. It
 * is what makes the price table in the tests a real check instead of a
 * fixture, and it is what keeps a stay of thirty nights from being thirty
 * round trips.
 */
final class NightPricingContext
{
    /**
     * @param  float  $baseRate  What the calendar says the night costs under
     *                           this plan. Per-unit reads it as the price of
     *                           the room; per-person-sharing reads it as the
     *                           price of one person sharing. Which one it
     *                           means is the strategy's business.
     * @param  array<int, GuestCategory>  $categories  keyed by id
     * @param  array<int, float>  $guestAmounts  number of guests => amount for
     *                                           this night, for the plans that
     *                                           price that way
     * @param  PricingConfig  $config  The strategy's own parameters. Passed
     *                                 rather than read off the rate plan, so a
     *                                 pricer never touches a model and a plan
     *                                 never has to know what a strategy needs.
     */
    public function __construct(
        public readonly BookableUnit $bookableUnit,
        public readonly Carbon $date,
        public readonly ?RatePlan $ratePlan,
        public readonly float $baseRate,
        public readonly Occupancy $occupancy,
        public readonly array $categories = [],
        public readonly array $guestAmounts = [],
        public readonly PricingConfig $config = new PricingConfig([]),
    ) {}

    public function planLabel(): string
    {
        return $this->ratePlan->name ?? 'the standard rate';
    }

    public function dateLabel(): string
    {
        return $this->date->isoFormat('D MMMM YYYY');
    }
}
