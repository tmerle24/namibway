<?php

namespace App\Services\Pricing;

/**
 * A priced stay, reduced to the facts a charge is allowed to see.
 *
 * The same idea as StaySummary next door, and for the same reason: a charge
 * applies *to* a finished price and must not be able to reach into how that
 * price was reached. Handing it a summary rather than the quotes is what makes
 * that a fact about the code rather than a rule somebody has to remember.
 */
final class ChargeableStay
{
    public function __construct(
        /** What the guest pays for the stay itself — after any discount or override. */
        public readonly float $stayAmount,
        public readonly int $nights,
        public readonly int $adults,
        public readonly int $children,
        /** Rooms × nights, which is what a per-room levy counts. */
        public readonly int $unitNights,
        public readonly string $currency,
    ) {}

    public function guests(bool $includingChildren): int
    {
        return $includingChildren ? $this->adults + $this->children : $this->adults;
    }
}
