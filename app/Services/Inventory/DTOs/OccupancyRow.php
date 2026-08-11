<?php

namespace App\Services\Inventory\DTOs;

use App\Models\BookableUnit;

/**
 * One room type's row: the bars across the top, packed into as few lanes as
 * they fit in, and the per-night cells underneath.
 *
 * Lanes are computed here rather than in the view because "how many rows tall
 * is this room type" has to be known before anything is drawn.
 */
class OccupancyRow
{
    /**
     * @param  array<int, array<int, OccupancyBar>>  $lanes  lane => bars, none overlapping
     * @param  array<int, OccupancyCell>  $cells  indexed by column position
     */
    public function __construct(
        public readonly BookableUnit $bookableUnit,
        public readonly array $lanes,
        public readonly array $cells,
        public readonly string $currency,
        /** Shown greyed: kept only because it still has stays or blocks on screen. */
        public readonly bool $isRetired,
    ) {}

    public function laneCount(): int
    {
        return max(1, count($this->lanes));
    }

    public function hasAnyBar(): bool
    {
        return $this->lanes !== [];
    }

    /**
     * Whether this unit is sold by departure. Read off a cell rather than
     * stored again: the cells are what know, and two answers to one question
     * is how a screen starts contradicting itself.
     */
    public function isDepartureUnit(): bool
    {
        return ($this->cells[0] ?? null)?->isDepartureDay() ?? false;
    }
}
