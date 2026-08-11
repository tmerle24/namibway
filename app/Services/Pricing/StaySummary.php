<?php

namespace App\Services\Pricing;

use Illuminate\Support\Carbon;

/**
 * A priced stay, reduced to the facts a promotion is allowed to see.
 *
 * Deliberately not the reservation, and deliberately not the quotes: a
 * promotion applies *to* a finished price and must not be able to reach into
 * how that price was reached. Handing it a summary rather than the machinery
 * is what makes that a fact about the code rather than a rule somebody has to
 * remember.
 */
final class StaySummary
{
    /**
     * @param  array<int, float>  $nightlyAmounts  every night of the booking as priced
     * @param  array<int, int>  $ratePlanIds
     * @param  array<int, int>  $bookableUnitIds
     */
    public function __construct(
        public readonly Carbon $checkIn,
        public readonly Carbon $checkOut,
        public readonly float $total,
        public readonly array $nightlyAmounts = [],
        public readonly array $ratePlanIds = [],
        public readonly array $bookableUnitIds = [],
    ) {}

    /** Half-open, like everything else here: the departure day is not a night. */
    public function nights(): int
    {
        return (int) $this->checkIn->diffInDays($this->checkOut);
    }
}
