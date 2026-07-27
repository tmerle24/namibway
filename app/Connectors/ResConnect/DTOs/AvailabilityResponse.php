<?php

namespace App\Connectors\ResConnect\DTOs;

final class AvailabilityResponse
{
    /**
     * @param  RoomType[]  $roomTypes
     */
    public function __construct(
        public readonly string $propertyCode,
        public readonly bool $available,
        public readonly array $roomTypes,
        public readonly ?string $error = null,
    ) {}

    public static function unavailable(string $propertyCode, string $reason): self
    {
        return new self(
            propertyCode: $propertyCode,
            available: false,
            roomTypes: [],
            error: $reason,
        );
    }
}
