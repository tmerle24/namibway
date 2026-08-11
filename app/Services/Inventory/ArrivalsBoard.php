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
 * The screen a property opens every morning: arrivals, departures and who is
 * staying on, for one date — and, where the property runs any, the passenger
 * list for each departure that day.
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
 *
 * ## Seats are not arrivals
 *
 * A booking that holds only seats on a tour is not an arrival, a departure or
 * an in-house stay: nobody checks in, nobody is given a key, and counting it
 * under "3 rooms arriving" is a number a desk would act on and be wrong. Such a
 * booking appears on its manifest and nowhere else. A booking that holds a
 * chalet *and* a ride is on both — the guest really does arrive.
 *
 * The manifests come from DayGrid, which is the calendar's own answer to "what
 * departs today and how full is it". One definition read twice, so a passenger
 * list carried to a vehicle and the grid on the office wall cannot disagree
 * about who is on the 09:00.
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
            // Null for every property that sells only nights, which is what
            // keeps the section off a lodge's screen entirely.
            manifests: app(DayGrid::class)->build($listing, $day),
            sellsNights: $this->sellsNights($listing),
        );
    }

    /**
     * Whether this property has anything sold by the night at all.
     *
     * False for an operator who only runs tours, and that is what takes the
     * arriving / departing / staying-on sections off their screen: three
     * headings saying "nobody" on every printed page is not a board, it is
     * somebody else's board with the rows removed.
     */
    private function sellsNights(Listing $listing): bool
    {
        return $listing->bookableUnits()
            ->where('is_active', true)
            ->whereDoesntHave('slots', fn (Builder $slots) => $slots->where('is_active', true))
            ->exists();
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
            // At least one line that holds a room. A booking made entirely of
            // seats has nobody to check in; it is on the manifest instead. A
            // booking with no lines at all is kept rather than hidden — there
            // is nothing to classify it by, and disappearing is worse than
            // appearing in the wrong list.
            ->where(fn (Builder $query) => $query
                ->whereHas('units', fn (Builder $units) => $units->whereNull('slot_id'))
                ->orWhereDoesntHave('units'))
            ->with(['units.bookableUnit'])
            ->orderBy('guest_name')
            ->orderBy('id');
    }
}
