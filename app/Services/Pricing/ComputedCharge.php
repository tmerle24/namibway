<?php

namespace App\Services\Pricing;

use App\Enums\ChargeBasis;
use App\Enums\ChargeKind;
use App\Models\Charge;

/**
 * One charge, worked out. Everything the frozen row needs and nothing that
 * would let a reader recompute it — see the reservation_charges migration.
 */
final class ComputedCharge
{
    public function __construct(
        public readonly ?Charge $charge,
        public readonly string $name,
        public readonly ChargeKind $kind,
        public readonly ChargeBasis $basis,
        public readonly float $rate,
        /** What it was worked out from — the first question anybody asks of an invoice line. */
        public readonly float $baseAmount,
        public readonly float $amount,
        /** Already inside the stay price rather than added to it. */
        public readonly bool $isIncluded,
        public readonly int $sort,
    ) {}

    /** @return array<string, mixed> */
    public function toRow(int $reservationId, string $currency): array
    {
        return [
            'reservation_id' => $reservationId,
            'charge_id' => $this->charge?->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'basis' => $this->basis,
            'rate' => $this->rate,
            'base_amount' => $this->baseAmount,
            'amount' => $this->amount,
            'is_included' => $this->isIncluded,
            'currency' => $currency,
            'sort' => $this->sort,
        ];
    }
}
