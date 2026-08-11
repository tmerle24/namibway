<?php

namespace App\Services\Inventory\DTOs;

use App\Models\GuestCategory;
use App\Models\RoomType;
use App\Services\Pricing\Occupancy;
use Illuminate\Support\Str;

/**
 * One room line of a manual booking, priced from the calendar.
 */
class ManualBookingLinePreview
{
    public function __construct(
        public readonly RoomType $roomType,
        public readonly int $quantity,
        public readonly float $total,
        public readonly string $currency,
        public readonly int $unitsFree,
        public readonly ?Occupancy $occupancy = null,
    ) {}

    /**
     * "2 adults, 1 child" — what the price was worked out from, said back to
     * whoever is typing it in.
     *
     * @param  array<int, GuestCategory>  $categories  keyed by id
     */
    public function occupancyLabel(array $categories): ?string
    {
        if ($this->occupancy === null || $this->occupancy->isEmpty()) {
            return null;
        }

        $parts = [];

        foreach ($this->occupancy->rows($categories) as $row) {
            // Str::plural knows "child" becomes "children"; naive pluralising
            // of a category name a lodge typed in would not.
            $parts[] = $row['count'].' '.Str::plural(Str::lower($row['category']->name), $row['count']);
        }

        return $parts === [] ? null : implode(', ', $parts);
    }
}
