<?php

namespace App\Services\Inventory\DTOs;

use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Models\Listing;

/**
 * Everything InventoryWriter::book() needs. A walk-in and a website booking
 * differ only in `source` and whether `inquiryId` is set — the inventory
 * mechanics are identical, which is the point of having one write path.
 */
class BookingRequest
{
    /**
     * @param  array<int, BookingLine>  $lines
     * @param  float|null  $totalOverride  What the lodge decided to charge, when that
     *                                     is not what the calendar priced. The calendar's
     *                                     own figure is kept either way — see the
     *                                     reservations price-override migration.
     */
    public function __construct(
        public readonly Listing $listing,
        public readonly array $lines,
        public readonly string $guestName,
        public readonly ?string $guestEmail = null,
        public readonly ?string $guestPhone = null,
        public readonly ReservationSource $source = ReservationSource::PartnerEntered,
        public readonly StayStatus $status = StayStatus::Confirmed,
        public readonly int $adults = 1,
        public readonly int $children = 0,
        public readonly ?string $notes = null,
        public readonly ?int $createdBy = null,
        public readonly ?int $inquiryId = null,
        public readonly ?float $totalOverride = null,
        public readonly ?string $overrideReason = null,
    ) {}
}
