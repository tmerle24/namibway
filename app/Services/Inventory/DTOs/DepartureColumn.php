<?php

namespace App\Services\Inventory\DTOs;

use App\Models\BookableUnit;
use App\Models\BookingSlot;
use Illuminate\Support\Carbon;

/**
 * One departure on one date, placed on the day's hour axis.
 *
 * `startIndex` and `span` are row positions on that axis — the same trick the
 * night grid uses for columns, turned ninety degrees — so the view places a
 * block by counting rows and never re-derives a time.
 *
 * `clippedEnd` says the departure runs past the bottom of the axis, which is
 * how a sunset drive ending after midnight reads. Its seats still belong to
 * the day it left on: the counter is keyed to the departure date, and minutes
 * are a scheduling problem this system deliberately does not have.
 */
class DepartureColumn
{
    /**
     * @param  array<int, DepartureStay>  $stays
     */
    public function __construct(
        public readonly BookableUnit $unit,
        public readonly BookingSlot $slot,
        public readonly Carbon $startsAt,
        public readonly Carbon $endsAt,
        public readonly int $startIndex,
        public readonly int $span,
        public readonly bool $clippedEnd,
        public readonly int $capacity,
        public readonly int $seatsSold,
        public readonly int $seatsBlocked,
        public readonly int $seatsFree,
        public readonly float $rate,
        public readonly string $currency,
        public readonly array $stays = [],
    ) {}

    public function label(): string
    {
        return $this->slot->label();
    }

    public function timeLabel(): string
    {
        return $this->startsAt->format('H:i').'–'.$this->endsAt->format('H:i');
    }

    /** "3 h", "90 min" — how long a guest is out for. */
    public function durationLabel(): string
    {
        $minutes = $this->slot->duration_minutes;

        if ($minutes % 60 === 0) {
            return ($minutes / 60).' h';
        }

        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h '.($minutes % 60).' min';
    }

    public function isSoldOut(): bool
    {
        return $this->seatsFree === 0;
    }

    /**
     * More seats held than the departure has. Same route as an overbooked
     * night: capacity lowered under what is already sold. Shown rather than
     * clamped, for the same reason.
     */
    public function isOverbooked(): bool
    {
        return $this->seatsFree < 0;
    }

    public function occupancyPercent(): int
    {
        return $this->capacity < 1 ? 0 : (int) round($this->seatsSold / $this->capacity * 100);
    }
}
