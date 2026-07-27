<?php

namespace App\Connectors\ResConnect\DTOs;

use Carbon\Carbon;

final class ReservationRequest
{
    public function __construct(
        public readonly string $propertyCode,
        public readonly string $roomTypeCode,
        public readonly Carbon $checkIn,
        public readonly Carbon $checkOut,
        public readonly int $adults,
        public readonly int $children,
        public readonly string $guestName,
        public readonly string $guestEmail,
        public readonly string $guestPhone,
        public readonly ?string $specialRequests = null,
        public readonly ?int $inquiryId = null,
    ) {}
}
