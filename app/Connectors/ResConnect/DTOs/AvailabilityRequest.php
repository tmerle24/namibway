<?php

namespace App\Connectors\ResConnect\DTOs;

use Carbon\CarbonInterface;

final class AvailabilityRequest
{
    public function __construct(
        public readonly string $propertyCode,
        public readonly CarbonInterface $checkIn,
        public readonly CarbonInterface $checkOut,
        public readonly int $adults = 2,
        public readonly int $children = 0,
    ) {}
}
