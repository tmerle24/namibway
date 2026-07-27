<?php

namespace App\Connectors\HopeCloud\DTOs;

/**
 * Namibia Tourism Levy details returned by hopeCloud.
 *
 * The Tourism Levy is a statutory charge per person per night on accommodation,
 * collected by the operator and reported to the Namibia Tourism Board (NTB).
 * hopeCloud automates this calculation and NTB/HAN reporting.
 */
final class TourismLevyInfo
{
    public function __construct(
        public readonly float $ratePerPersonPerNight,
        public readonly string $currency,
        public readonly float $totalLevy,
        public readonly int $nights,
        public readonly int $persons,
        public readonly bool $includedInRate,
    ) {}

    public static function notApplicable(): self
    {
        return new self(
            ratePerPersonPerNight: 0,
            currency: 'NAD',
            totalLevy: 0,
            nights: 0,
            persons: 0,
            includedInRate: false,
        );
    }
}
