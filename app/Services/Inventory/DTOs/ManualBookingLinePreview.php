<?php

namespace App\Services\Inventory\DTOs;

use App\Models\RoomType;

/**
 * One room type's share of a manual booking, priced from the calendar.
 */
class ManualBookingLinePreview
{
    public function __construct(
        public readonly RoomType $roomType,
        public readonly int $quantity,
        public readonly float $total,
        public readonly string $currency,
        public readonly int $unitsFree,
    ) {}
}
