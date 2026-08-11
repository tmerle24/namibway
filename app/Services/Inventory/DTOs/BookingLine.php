<?php

namespace App\Services\Inventory\DTOs;

use App\Models\RatePlan;
use App\Models\RoomType;
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
     */
    public function __construct(
        public readonly RoomType $roomType,
        public readonly int $quantity,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        public readonly ?RatePlan $ratePlan = null,
    ) {
        $this->checkIn = Carbon::parse($checkIn)->startOfDay();
        $this->checkOut = Carbon::parse($checkOut)->startOfDay();
    }

    public function nights(): int
    {
        return (int) $this->checkIn->diffInDays($this->checkOut);
    }
}
