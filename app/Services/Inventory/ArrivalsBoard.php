<?php

namespace App\Services\Inventory;

use App\Enums\StayStatus;
use App\Models\Listing;
use App\Models\Reservation;
use App\Services\Inventory\DTOs\ArrivalsBoardData;
use App\Support\CountrySettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The screen a lodge opens every morning: arrivals, departures and who is
 * staying on, for one date.
 *
 * **Read only** — it never calls InventoryWriter.
 *
 * Movements are read from the reservation header rather than from its lines.
 * The writer sets `check_in` / `check_out` to the earliest and latest across
 * the lines, and a front desk asks about the guest, not about each room type
 * they hold: someone whose second room starts a day later still arrives today.
 *
 * Half-open, like everything else here: a stay ending today is a departure and
 * not in house, so a room that turns over is counted once on each side.
 */
class ArrivalsBoard
{
    public function forDate(Listing $listing, CarbonInterface $date): ArrivalsBoardData
    {
        $day = Carbon::parse($date)->startOfDay();
        $stamp = $day->toDateString();

        return new ArrivalsBoardData(
            listing: $listing,
            date: $day,
            today: CountrySettings::for($listing)->today(),
            arrivals: $this->query($listing)->whereDate('check_in', $stamp)->get(),
            departures: $this->query($listing)->whereDate('check_out', $stamp)->get(),
            inHouse: $this->query($listing)
                ->whereDate('check_in', '<', $stamp)
                ->whereDate('check_out', '>', $stamp)
                ->get(),
        );
    }

    /**
     * Cancelled stays are gone from the book and off the board; a no-show is
     * not — it is a room that was held for someone who never came, and the
     * desk has to see it.
     *
     * @return Builder<Reservation>
     */
    private function query(Listing $listing): Builder
    {
        return Reservation::query()
            ->where('listing_id', $listing->id)
            ->whereIn('status', array_map(fn (StayStatus $status) => $status->value, StayStatus::occupying()))
            ->with(['units.roomType'])
            ->orderBy('guest_name')
            ->orderBy('id');
    }
}
