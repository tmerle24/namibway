<?php

namespace App\Services\Inventory\DTOs;

use App\Services\Pricing\ComputedCharge;

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
     * @param  float  $discount  What an offer took off, already subtracted from the total
     * @param  string|null  $offer  What to call it on screen
     * @param  array<int, ComputedCharge>  $charges  Taxes, levies and fees as they would be
     *                                               frozen onto the stay. The added ones are
     *                                               already inside $total; an included one is
     *                                               named without moving it.
     */
    public function __construct(
        public readonly float $total,
        public readonly string $currency,
        public readonly int $nights,
        public readonly array $problems = [],
        public readonly array $lines = [],
        public readonly float $discount = 0.0,
        public readonly ?string $offer = null,
        public readonly array $charges = [],
    ) {}

    /** What the guest pays for the stay itself, before anything is added on top. */
    public function stayAmount(): float
    {
        $added = 0.0;

        foreach ($this->charges as $charge) {
            if (! $charge->isIncluded) {
                $added += $charge->amount;
            }
        }

        return round($this->total - $added, 2);
    }

    /**
     * What the stay costs before an offer came off it. Shown beside the total
     * rather than instead of it: a guest handed a code wants to see the
     * discount, and a desk explaining the bill needs both numbers.
     */
    public function beforeDiscount(): float
    {
        return round($this->stayAmount() + $this->discount, 2);
    }

    public function isBookable(): bool
    {
        return $this->problems === [] && $this->lines !== [] && $this->nights > 0;
    }
}
