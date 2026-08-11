<?php

namespace App\Services\Inventory\DTOs;

use App\Enums\BlockReason;
use App\Models\RoomType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Units taken off sale. `firstNight` and `lastNight` are both inclusive — a
 * block is about nights, so naming them after nights removes the off-by-one
 * that reusing check-in/check-out here would invite.
 */
class BlockRequest
{
    public readonly Carbon $firstNight;

    public readonly Carbon $lastNight;

    public function __construct(
        public readonly RoomType $roomType,
        public readonly int $units,
        CarbonInterface $firstNight,
        CarbonInterface $lastNight,
        public readonly BlockReason $reason = BlockReason::Maintenance,
        public readonly ?string $note = null,
        public readonly ?int $createdBy = null,
    ) {
        $this->firstNight = Carbon::parse($firstNight)->startOfDay();
        $this->lastNight = Carbon::parse($lastNight)->startOfDay();
    }
}
