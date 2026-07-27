<?php

namespace App\Connectors\ResConnect\DTOs;

final class RoomType
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $available,
        public readonly float $ratePerNight,
        public readonly string $currency,
        public readonly float $totalRate,
        public readonly ?string $mealPlan = null,
        public readonly ?string $description = null,
    ) {}
}
