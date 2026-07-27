<?php

namespace App\Connectors\ResConnect\DTOs;

use Carbon\Carbon;

final class AvailabilityRequest
{
    public function __construct(
        public readonly string $propertyCode,
        public readonly Carbon $checkIn,
        public readonly Carbon $checkOut,
        public readonly int $adults = 2,
        public readonly int $children = 0,
    ) {}
}
