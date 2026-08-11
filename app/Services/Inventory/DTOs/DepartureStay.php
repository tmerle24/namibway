<?php

namespace App\Services\Inventory\DTOs;

use App\Enums\StayStatus;

/**
 * One booking on one departure: who, how many seats, and where the stay has
 * got to.
 *
 * Deliberately not an OccupancyBar. A bar carries a start column and a span
 * because a stay covers a range of nights; a seat sale covers exactly one
 * departure, which is the column it sits in. Giving it a span would invite the
 * view to place it by arithmetic that has no meaning here.
 */
class DepartureStay
{
    public function __construct(
        public readonly int $reservationId,
        public readonly string $label,
        public readonly int $seats,
        public readonly StayStatus $state,
        public readonly ?string $reference = null,
    ) {}

    public function stateLabel(): string
    {
        return $this->state->label();
    }

    public function color(): string
    {
        return $this->state->color();
    }
}
