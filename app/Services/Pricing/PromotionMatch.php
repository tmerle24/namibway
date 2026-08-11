<?php

namespace App\Services\Pricing;

use App\Models\Promotion;

/**
 * The offer that applied, and what it took off. Kept together because the
 * amount without the offer is a number nobody can explain afterwards.
 */
final class PromotionMatch
{
    public function __construct(
        public readonly Promotion $promotion,
        public readonly float $amount,
    ) {}
}
