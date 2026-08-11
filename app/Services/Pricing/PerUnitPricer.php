<?php

namespace App\Services\Pricing;

/**
 * One amount for the room, however many people are in it.
 *
 * How self-catering units, guest farms and campsites sell, and the behaviour
 * the whole system had before rate plans existed — which is why this one
 * ignores the occupancy rather than refusing when it is empty.
 */
final class PerUnitPricer implements NightPricer
{
    public function price(NightPricingContext $context): float
    {
        return round($context->baseRate, 2);
    }
}
