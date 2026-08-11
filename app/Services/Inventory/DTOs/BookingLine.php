<?php

namespace App\Services\Inventory\DTOs;

use App\Models\BookableUnit;
use App\Models\BookingSlot;
use App\Models\RatePlan;
use App\Services\Pricing\Occupancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * One room type, one quantity, one date range. A reservation is a guest plus
 * a list of these — which is the thing today's Inquiry cannot express, since
 * it has no quantity column and so treats three rooms as three requests.
 *
 * Dates are half-open: check_out is a departure day, not a night.
 */
class BookingLine
{
    public readonly Carbon $checkIn;

    public readonly Carbon $checkOut;

    /**
     * @param  RatePlan|null  $ratePlan  Which product this room was sold under.
     *                                   Null lets the writer fall back to the
     *                                   property's default plan — a front desk
     *                                   that has never heard of rate plans
     *                                   should not have to name one.
     * @param  Occupancy|null  $occupancy  Who is in the room. Null is the right
     *                                     answer for a per-room tariff, which
     *                                     never asks; a plan that prices by
     *                                     guests refuses the line instead of
     *                                     assuming a number of people.
     * @param  BookingSlot|null  $slot  Which departure, for a unit sold by the hour.
     *                                  Null for everything sold by the period, which
     *                                  is every stay a lodge takes — the inventory it
     *                                  consumes is then the day's, exactly as before.
     */
    public function __construct(
        public readonly BookableUnit $bookableUnit,
        public readonly int $quantity,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        public readonly ?RatePlan $ratePlan = null,
        public readonly ?Occupancy $occupancy = null,
        public readonly ?BookingSlot $slot = null,
    ) {
        $this->checkIn = Carbon::parse($checkIn)->startOfDay();
        $this->checkOut = Carbon::parse($checkOut)->startOfDay();
    }

    public function nights(): int
    {
        return (int) $this->checkIn->diffInDays($this->checkOut);
    }
}
