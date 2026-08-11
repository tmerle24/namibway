<?php

namespace App\Filament\Partner\Pages\Concerns;

use App\Models\InventoryBlock;
use App\Models\Listing;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;

/**
 * The read-only stay detail both lodge-facing screens open — from a bar on the
 * calendar and from a row on the arrivals board.
 *
 * The id arrives from the browser, so it is resolved through the selected
 * property every time rather than looked up directly. A stay belonging to
 * another partner therefore does not open; it does not exist as far as this
 * page is concerned.
 *
 * Nothing here writes. Changing a stay is slice 3.
 */
trait ShowsReservationDetail
{
    public ?int $selectedReservationId = null;

    public ?int $selectedBlockId = null;

    public function showReservation(int $reservationId): void
    {
        $this->selectedBlockId = null;
        $this->selectedReservationId = $this->lookUp($reservationId)?->id;
    }

    /**
     * Blocks open the same drawer. A block has no guest, so what a lodge wants
     * to see is why the rooms are off sale and who took them off.
     */
    public function showBlock(int $blockId): void
    {
        $this->selectedReservationId = null;
        $this->selectedBlockId = $this->lookUpBlock($blockId)?->id;
    }

    public function closeReservation(): void
    {
        $this->selectedReservationId = null;
        $this->selectedBlockId = null;
    }

    public function selectedReservation(): ?Reservation
    {
        return $this->selectedReservationId === null ? null : $this->lookUp($this->selectedReservationId);
    }

    public function selectedBlock(): ?InventoryBlock
    {
        return $this->selectedBlockId === null ? null : $this->lookUpBlock($this->selectedBlockId);
    }

    private function lookUpBlock(int $blockId): ?InventoryBlock
    {
        $property = $this->property();

        if ($property === null) {
            return null;
        }

        return InventoryBlock::query()
            ->whereHas('roomType', fn (Builder $query) => $query->where('listing_id', $property->id))
            ->with('roomType')
            ->find($blockId);
    }

    private function lookUp(int $reservationId): ?Reservation
    {
        $property = $this->property();

        if ($property === null) {
            return null;
        }

        return Reservation::query()
            ->where('listing_id', $property->id)
            ->with(['units.roomType', 'units.nights', 'units.guests.category', 'charges'])
            ->find($reservationId);
    }

    /** The property the page is showing — see SelectedProperty. */
    abstract protected function property(): ?Listing;
}
