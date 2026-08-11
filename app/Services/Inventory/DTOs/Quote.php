<?php

namespace App\Services\Inventory\DTOs;

/**
 * The price of a stay, night by night. Kept as the breakdown rather than a
 * single total because the breakdown is what gets written to
 * reservation_nights and what a guest is shown when they ask why a stay costs
 * what it costs.
 */
class Quote
{
    /**
     * @param  array<int, NightlyRate>  $nights
     */
    public function __construct(
        public readonly array $nights,
        public readonly string $currency,
    ) {}

    public function total(): float
    {
        return round(array_sum(array_map(fn (NightlyRate $night) => $night->subtotal(), $this->nights)), 2);
    }

    public function nightCount(): int
    {
        return count($this->nights);
    }
}
