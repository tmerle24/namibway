<?php

namespace App\Services\Inventory\DTOs;

use Illuminate\Support\Carbon;

/**
 * What one night costs, per unit. The reason a stay crossing a season
 * boundary needs no special case: it is simply a list of these.
 */
class NightlyRate
{
    public function __construct(
        public readonly Carbon $date,
        public readonly float $rate,
        public readonly int $units,
    ) {}

    public function subtotal(): float
    {
        return $this->rate * $this->units;
    }
}
