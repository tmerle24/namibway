<?php

namespace App\Services\Pricing;

use App\Enums\PricingStrategy;

/**
 * Which calculation a rate plan's strategy means.
 *
 * A `match` in one place rather than a method on the enum: the enum is a
 * column's vocabulary and belongs in App\Enums, while a pricer is a service.
 * Keeping the two apart is what lets a strategy grow dependencies later
 * without the enum growing them too.
 */
final class Pricers
{
    public static function for(PricingStrategy $strategy): NightPricer
    {
        return match ($strategy) {
            PricingStrategy::PerUnit => new PerUnitPricer,
            PricingStrategy::PerOccupancy => new PerOccupancyPricer,
            PricingStrategy::PerPersonSharing => new PerPersonSharingPricer,
        };
    }
}
