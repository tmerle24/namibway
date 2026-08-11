<?php

namespace App\Services\Inventory\DTOs;

use App\Enums\BlockReason;
use App\Enums\StayStatus;
use Illuminate\Support\Carbon;

/**
 * A stay or a block drawn across the days it covers, already clipped to the
 * visible range. `startIndex` and `span` are column positions, not dates: the
 * view places a bar by counting columns and never re-derives a date.
 *
 * `clippedStart` / `clippedEnd` say the thing runs past the edge of what is on
 * screen, which the view shows as a cut-off edge — a bar that simply stops at
 * the border would read as a departure that day.
 */
class OccupancyBar
{
    public const KIND_RESERVATION = 'reservation';

    public const KIND_BLOCK = 'block';

    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly string $label,
        public readonly int $units,
        public readonly int $startIndex,
        public readonly int $span,
        public readonly bool $clippedStart,
        public readonly bool $clippedEnd,
        public readonly Carbon $checkIn,
        public readonly Carbon $checkOut,
        public readonly StayStatus|BlockReason $state,
        public readonly ?string $reference = null,
    ) {}

    public function isReservation(): bool
    {
        return $this->kind === self::KIND_RESERVATION;
    }

    public function endIndex(): int
    {
        return $this->startIndex + $this->span;
    }

    public function stateLabel(): string
    {
        return $this->state->label();
    }

    /** Nights, not days — the 5th to the 8th is three. */
    public function nights(): int
    {
        return (int) $this->checkIn->diffInDays($this->checkOut);
    }
}
