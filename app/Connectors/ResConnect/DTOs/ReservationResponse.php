<?php

namespace App\Connectors\ResConnect\DTOs;

use App\Enums\ReservationStatus;

/** @phpstan-consistent-constructor */
class ReservationResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ReservationStatus $status,
        public readonly ?string $connectorReference = null,
        public readonly ?string $confirmationNumber = null,
        public readonly ?float $totalAmount = null,
        public readonly ?string $currency = null,
        public readonly ?string $error = null,
    ) {}

    public static function failed(string $reason): static
    {
        return new static(
            success: false,
            status: ReservationStatus::Failed,
            error: $reason,
        );
    }
}
