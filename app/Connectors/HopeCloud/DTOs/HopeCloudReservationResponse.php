<?php

namespace App\Connectors\HopeCloud\DTOs;

use App\Connectors\ResConnect\DTOs\ReservationResponse;
use App\Enums\ReservationStatus;

/**
 * Extended reservation response that includes Namibia Tourism Levy details.
 */
final class HopeCloudReservationResponse extends ReservationResponse
{
    public function __construct(
        bool $success,
        ReservationStatus $status,
        ?string $connectorReference = null,
        ?string $confirmationNumber = null,
        ?float $totalAmount = null,
        ?string $currency = null,
        ?string $error = null,
        public readonly ?TourismLevyInfo $tourismLevy = null,
        public readonly ?string $ntbReportReference = null,
    ) {
        parent::__construct(
            success: $success,
            status: $status,
            connectorReference: $connectorReference,
            confirmationNumber: $confirmationNumber,
            totalAmount: $totalAmount,
            currency: $currency,
            error: $error,
        );
    }

    public static function failed(string $reason): self
    {
        return new self(
            success: false,
            status: ReservationStatus::Failed,
            error: $reason,
        );
    }
}
