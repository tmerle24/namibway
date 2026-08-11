<?php

namespace App\Services\Inventory\DTOs;

use App\Models\BookingSlot;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Pricing\Occupancy;

/**
 * One room line of a manual booking, after the form's strings have been turned
 * into things: a room type of this property, a rate plan of this property, and
 * who is in the room.
 *
 * A type rather than an array with four keys, because both readers — the
 * preview and the write — carry it around, and an array shape that survives
 * one merge step is an array shape nobody can check.
 */
final class ResolvedRoomLine
{
    public function __construct(
        public readonly RoomType $roomType,
        public readonly int $quantity,
        public readonly ?RatePlan $ratePlan = null,
        public readonly ?Occupancy $occupancy = null,
        /** Which departure, for a unit sold by the hour. Null is every night. */
        public readonly ?BookingSlot $slot = null,
    ) {}

    /**
     * The same line with more rooms on it. Immutable, so merging two identical
     * form rows produces a new line rather than quietly editing one already
     * handed out.
     */
    public function plus(int $quantity): self
    {
        return new self($this->roomType, $this->quantity + $quantity, $this->ratePlan, $this->occupancy, $this->slot);
    }
}
