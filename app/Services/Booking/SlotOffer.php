<?php

namespace App\Services\Booking;

/**
 * One bookable departure on a given date — the slot-level counterpart of a
 * priced room: seats still free, what a seat costs, and the display strings
 * a picker needs without further lookups.
 */
final class SlotOffer
{
    public function __construct(
        /** "09:00" */
        public readonly string $startsAt,
        /** "Morning ride" where the operator named it, the time where they did not. */
        public readonly string $label,
        public readonly int $durationMinutes,
        public readonly int $unitsLeft,
        public readonly float $total,
        /** Equal to `total` until per-slot charges are added — placeholder for now. */
        public readonly float $totalPayable,
    ) {}
}
