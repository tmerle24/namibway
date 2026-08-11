<?php

namespace App\Services\Booking;

use App\Models\RoomType;
use App\Services\Pricing\ComputedCharge;

/**
 * One room a traveller can actually book, at the price they will actually pay.
 *
 * "Actually" is the whole point. Until now the trip plan quoted a room type's
 * base rate multiplied by the nights, which knew nothing about seasons, rate
 * plans, occupancy or tax — so the moment a property entered real rates, the
 * number a traveller was shown and the number the lodge charged were two
 * different numbers.
 */
final class RoomOffer
{
    /**
     * @param  int<1, max>  $unitsLeft
     * @param  array<int, float>  $nightlyRates  each night of the stay as the calendar priced it
     * @param  array<int, ComputedCharge>  $charges
     */
    public function __construct(
        public readonly RoomType $roomType,
        public readonly int $unitsLeft,
        public readonly int $nights,
        public readonly string $currency,
        public readonly array $nightlyRates,
        /** What the room costs, before anything is added on top. */
        public readonly float $stayAmount,
        public readonly array $charges,
        /** What the traveller pays, taxes and fees included. */
        public readonly float $totalPayable,
    ) {}

    /**
     * The stay divided by its nights. A single figure for a screen that shows
     * "per night", honest about being an average: a stay that crosses a season
     * boundary genuinely has two rates, and the nightly list is there for
     * anybody who wants to show them.
     */
    public function averageNightly(): float
    {
        return $this->nights < 1 ? 0.0 : round($this->stayAmount / $this->nights, 2);
    }

    /** What is added on top of the stay — zero when the rates already contain it. */
    public function addedCharges(): float
    {
        return round($this->totalPayable - $this->stayAmount, 2);
    }
}
