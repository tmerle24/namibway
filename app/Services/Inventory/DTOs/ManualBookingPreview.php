<?php

namespace App\Services\Inventory\DTOs;

/**
 * What a manual booking would cost and what stands in its way, worked out
 * before anything is written.
 *
 * The writer already refuses an impossible booking — it has to, because two
 * people can be typing at once. This exists so the person at the desk is told
 * *which* room type is short on *which* night while the form is still open,
 * rather than after pressing save.
 */
class ManualBookingPreview
{
    /**
     * @param  array<int, string>  $problems  Plain sentences a front desk can act on
     * @param  array<int, ManualBookingLinePreview>  $lines
     */
    public function __construct(
        public readonly float $total,
        public readonly string $currency,
        public readonly int $nights,
        public readonly array $problems = [],
        public readonly array $lines = [],
    ) {}

    public function isBookable(): bool
    {
        return $this->problems === [] && $this->lines !== [] && $this->nights > 0;
    }
}
