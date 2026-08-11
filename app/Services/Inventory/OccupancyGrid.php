<?php

namespace App\Services\Inventory;

use App\Enums\BlockReason;
use App\Enums\StayStatus;
use App\Models\BookingSlot;
use App\Models\InventoryBlock;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Services\Inventory\DTOs\CalendarSnapshot;
use App\Services\Inventory\DTOs\OccupancyBar;
use App\Services\Inventory\DTOs\OccupancyCell;
use App\Services\Inventory\DTOs\OccupancyColumn;
use App\Services\Inventory\DTOs\OccupancyGridData;
use App\Services\Inventory\DTOs\OccupancyRow;
use App\Support\CountrySettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Builds the occupancy calendar for one property: room types as rows, nights
 * as columns, stays and blocks as bars.
 *
 * **Read only.** Nothing here writes, and nothing here calls InventoryWriter —
 * the calendar screen shows the book, it does not keep it.
 *
 * ## Why the query count is flat
 *
 * A month across twenty room types is six hundred cells. Asking the calendar
 * per cell is six hundred queries and a reception desk on a satellite link
 * that never finishes loading. So everything is fetched in bulk up front —
 * room types, calendar nights, stays, blocks — and every cell is then answered
 * from memory. The count does not grow with the number of room types or the
 * length of the range; a wider range fetches more rows, not more queries.
 */
class OccupancyGrid
{
    /** A month is what a lodge plans in; anything longer stops being readable. */
    public const DEFAULT_DAYS = 30;

    public function __construct(private readonly AvailabilityCalendar $calendar) {}

    /**
     * `$ratePlan` is which product's rates the cells show. Null means the
     * property's default plan, and a property with none shows its room types'
     * own rates — the behaviour before rate plans existed.
     *
     * `$omitDepartureUnits` leaves out the units that run departures, for the
     * day view, where those are read on the hour axis instead. Everywhere else
     * they stay in, summed per day — see cells().
     */
    public function build(
        Listing $listing,
        CarbonInterface $from,
        int $days = self::DEFAULT_DAYS,
        ?RatePlan $ratePlan = null,
        bool $omitDepartureUnits = false,
    ): OccupancyGridData {
        $ratePlan ??= RatePlan::defaultFor($listing);

        $days = max(1, min($days, 92));
        $start = Carbon::parse($from)->startOfDay();
        $end = $start->copy()->addDays($days);
        $today = CountrySettings::for($listing)->today();

        $roomTypes = $listing->roomTypes()->orderBy('name')->get();
        $snapshot = $this->calendar->snapshot($roomTypes, $start, $end, $ratePlan);
        $slots = $this->slots($roomTypes->pluck('id')->all());

        $bars = $this->bars($listing, $roomTypes->pluck('id')->all(), $start, $end, $days);

        $dates = [];

        for ($index = 0; $index < $days; $index++) {
            $dates[$index] = $start->copy()->addDays($index);
        }

        $rows = [];

        foreach ($roomTypes as $roomType) {
            $roomBars = $bars[$roomType->id] ?? [];
            $unitSlots = $slots[$roomType->id] ?? [];

            if ($omitDepartureUnits && $unitSlots !== []) {
                continue;
            }

            // An inactive room type is normally noise, but one with a stay or
            // a block still on screen is not: hiding it would hide a booking.
            if (! $roomType->is_active && $roomBars === [] && ! $snapshot->hasActivity($roomType->id)) {
                continue;
            }

            $rows[] = new OccupancyRow(
                roomType: $roomType,
                lanes: $this->packLanes($roomBars),
                cells: $this->cells($roomType, $snapshot, $dates, $unitSlots),
                currency: CountrySettings::currencyForRoomType($roomType),
                isRetired: ! $roomType->is_active,
            );
        }

        return new OccupancyGridData(
            listing: $listing,
            columns: $this->columns($dates, $rows, $today),
            rows: $rows,
            from: $start,
            to: $end,
            today: $today,
            currency: CountrySettings::for($listing)->currency(),
        );
    }

    /**
     * @param  array<int, Carbon>  $dates
     * @param  array<int, BookingSlot>  $slots  the unit's timetable, empty for anything sold by the night
     * @return array<int, OccupancyCell>
     */
    private function cells(RoomType $roomType, CalendarSnapshot $snapshot, array $dates, array $slots = []): array
    {
        $cells = [];

        foreach ($dates as $index => $date) {
            $key = $date->toDateString();

            if ($slots !== []) {
                $cells[$index] = $this->departureCell($roomType, $snapshot, $date, $slots);

                continue;
            }

            // Counters come from the inventory row, restrictions from the rate
            // plan's — they are commercial, not physical, so a non-refundable
            // plan can want a three-night minimum on a night the flexible plan
            // sells singly.
            $restrictions = $snapshot->rateDay($roomType->id, $key);

            $cells[$index] = new OccupancyCell(
                date: $date,
                capacity: $snapshot->capacity($roomType->id, $key),
                unitsSold: $snapshot->sold($roomType->id, $key),
                unitsBlocked: $snapshot->blocked($roomType->id, $key),
                unitsFree: $snapshot->unitsFree($roomType->id, $key),
                rate: $snapshot->rate($roomType->id, $key),
                minStay: $restrictions?->min_stay,
                closedToArrival: (bool) $restrictions?->closed_to_arrival,
                closedToDeparture: (bool) $restrictions?->closed_to_departure,
            );
        }

        return $cells;
    }

    /**
     * A day of departures as one cell: seats summed across the timetable, and
     * the cheapest seat as the price.
     *
     * Without this a tour operator's week would read "8 free" on every day
     * forever, because its counters are on the departures' rows and the
     * property's own row is the one nobody ever writes. Restrictions are left
     * empty on purpose — a minimum stay is a rule about nights, and a
     * departure is not one.
     *
     * @param  array<int, BookingSlot>  $slots
     */
    private function departureCell(RoomType $roomType, CalendarSnapshot $snapshot, Carbon $date, array $slots): OccupancyCell
    {
        $key = $date->toDateString();
        $capacity = 0;
        $sold = 0;
        $blocked = 0;
        $rate = null;

        foreach ($slots as $slot) {
            $day = $snapshot->slotDay($roomType->id, $key, $slot->id);

            $capacity += CalendarSnapshot::capacityFor($roomType, $day);
            $sold += $day === null ? 0 : $day->units_sold;
            $blocked += $day === null ? 0 : $day->units_blocked;

            // The same three steps the booking path takes: the departure's own
            // rate, else the day's, else the unit's.
            $seat = CalendarSnapshot::rateFor(
                $roomType,
                $snapshot->slotRateDay($roomType->id, $key, $slot->id) ?? $snapshot->rateDay($roomType->id, $key),
            );

            $rate = $rate === null ? $seat : min($rate, $seat);
        }

        return new OccupancyCell(
            date: $date,
            capacity: $capacity,
            unitsSold: $sold,
            unitsBlocked: $blocked,
            unitsFree: $capacity - $sold - $blocked,
            rate: $rate ?? $snapshot->rate($roomType->id, $key),
            minStay: null,
            closedToArrival: false,
            closedToDeparture: false,
            departures: count($slots),
        );
    }

    /**
     * The active timetable of every unit that has one, grouped by unit.
     *
     * One query for the whole property, like everything else here: a lookup
     * per room type is the shape this class exists to avoid.
     *
     * @param  array<int, int>  $roomTypeIds
     * @return array<int, array<int, BookingSlot>>
     */
    private function slots(array $roomTypeIds): array
    {
        if ($roomTypeIds === []) {
            return [];
        }

        $slots = [];

        $rows = BookingSlot::query()
            ->whereIn('room_type_id', $roomTypeIds)
            ->where('is_active', true)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $slot) {
            $slots[$slot->room_type_id][] = $slot;
        }

        return $slots;
    }

    /**
     * @param  array<int, Carbon>  $dates
     * @param  array<int, OccupancyRow>  $rows
     * @return array<int, OccupancyColumn>
     */
    private function columns(array $dates, array $rows, Carbon $today): array
    {
        $columns = [];
        $previousMonth = null;

        foreach ($dates as $index => $date) {
            $capacity = 0;
            $sold = 0;
            $blocked = 0;
            $free = 0;

            foreach ($rows as $row) {
                $cell = $row->cells[$index];
                $capacity += $cell->capacity;
                $sold += $cell->unitsSold;
                $blocked += $cell->unitsBlocked;
                $free += $cell->unitsFree;
            }

            $month = $date->format('Y-m');

            $columns[$index] = new OccupancyColumn(
                date: $date,
                isToday: $date->isSameDay($today),
                isWeekend: $date->isWeekend(),
                isPast: $date->lt($today),
                startsMonth: $month !== $previousMonth,
                unitsFree: $free,
                capacity: $capacity,
                unitsSold: $sold,
                unitsBlocked: $blocked,
            );

            $previousMonth = $month;
        }

        return $columns;
    }

    /**
     * Stays and blocks overlapping the window, clipped to it and grouped by
     * room type.
     *
     * Stays are half-open — a stay ending the day another begins does not
     * overlap it — while a block counts nights and is inclusive at both ends.
     * That difference is the reason the two are converted here rather than in
     * the view.
     *
     * @param  array<int, int>  $roomTypeIds
     * @return array<int, array<int, OccupancyBar>>
     */
    private function bars(Listing $listing, array $roomTypeIds, Carbon $start, Carbon $end, int $days): array
    {
        if ($roomTypeIds === []) {
            return [];
        }

        $bars = [];

        $units = ReservationUnit::query()
            ->whereIn('room_type_id', $roomTypeIds)
            // Nights only. A seat sold on a departure is drawn on the day
            // view, where it has a time; drawn here it would be a bar one
            // column wide, and a tour with forty bookings in a month would
            // stack forty lanes — lane packing is deliberately unbounded, so
            // nothing would stop it.
            ->whereNull('slot_id')
            ->whereDate('check_in', '<', $end->toDateString())
            ->whereDate('check_out', '>', $start->toDateString())
            ->whereHas('reservation', fn (Builder $query) => $query
                ->where('listing_id', $listing->id)
                ->whereIn('status', array_map(fn (StayStatus $status) => $status->value, StayStatus::occupying())))
            ->with('reservation')
            ->orderBy('check_in')
            ->orderBy('id')
            ->get();

        foreach ($units as $unit) {
            $reservation = $unit->reservation;

            if ($reservation === null) {
                continue;
            }

            $bar = $this->clip(
                kind: OccupancyBar::KIND_RESERVATION,
                id: $reservation->id,
                label: $reservation->guest_name,
                units: $unit->quantity,
                checkIn: Carbon::parse($unit->check_in)->startOfDay(),
                checkOut: Carbon::parse($unit->check_out)->startOfDay(),
                state: $reservation->status,
                reference: $reservation->reference,
                start: $start,
                days: $days,
            );

            if ($bar !== null) {
                $bars[$unit->room_type_id][] = $bar;
            }
        }

        $blocks = InventoryBlock::query()
            ->whereIn('room_type_id', $roomTypeIds)
            ->whereNull('released_at')
            ->whereDate('first_night', '<', $end->toDateString())
            ->whereDate('last_night', '>=', $start->toDateString())
            ->orderBy('first_night')
            ->orderBy('id')
            ->get();

        foreach ($blocks as $block) {
            $bar = $this->clip(
                kind: OccupancyBar::KIND_BLOCK,
                id: $block->id,
                label: $block->reason->label(),
                units: $block->units,
                checkIn: Carbon::parse($block->first_night)->startOfDay(),
                // Both ends of a block are nights, so it releases the morning
                // after the last one — the same shape as a check-out date.
                checkOut: Carbon::parse($block->last_night)->startOfDay()->addDay(),
                state: $block->reason,
                reference: null,
                start: $start,
                days: $days,
            );

            if ($bar !== null) {
                $bars[$block->room_type_id][] = $bar;
            }
        }

        return $bars;
    }

    private function clip(
        string $kind,
        int $id,
        string $label,
        int $units,
        Carbon $checkIn,
        Carbon $checkOut,
        StayStatus|BlockReason $state,
        ?string $reference,
        Carbon $start,
        int $days,
    ): ?OccupancyBar {
        $visibleStart = $checkIn->lt($start) ? $start : $checkIn;
        $end = $start->copy()->addDays($days);
        $visibleEnd = $checkOut->gt($end) ? $end : $checkOut;

        $startIndex = (int) $start->diffInDays($visibleStart);
        $span = (int) $visibleStart->diffInDays($visibleEnd);

        if ($span < 1) {
            return null;
        }

        return new OccupancyBar(
            kind: $kind,
            id: $id,
            label: $label,
            units: $units,
            startIndex: $startIndex,
            span: $span,
            clippedStart: $checkIn->lt($start),
            clippedEnd: $checkOut->gt($end),
            checkIn: $checkIn,
            checkOut: $checkOut,
            state: $state,
            reference: $reference,
        );
    }

    /**
     * First-fit lane packing: a bar goes in the topmost lane whose last bar
     * has already ended. Bars arrive sorted by start, so one pass is enough.
     *
     * @param  array<int, OccupancyBar>  $bars
     * @return array<int, array<int, OccupancyBar>>
     */
    private function packLanes(array $bars): array
    {
        usort($bars, fn (OccupancyBar $a, OccupancyBar $b) => [$a->startIndex, -$a->span] <=> [$b->startIndex, -$b->span]);

        /** @var array<int, array<int, OccupancyBar>> $lanes */
        $lanes = [];
        /** @var array<int, int> $laneEnds */
        $laneEnds = [];

        foreach ($bars as $bar) {
            $placed = false;

            foreach ($laneEnds as $lane => $endsAt) {
                if ($endsAt <= $bar->startIndex) {
                    $lanes[$lane][] = $bar;
                    $laneEnds[$lane] = $bar->endIndex();
                    $placed = true;
                    break;
                }
            }

            if (! $placed) {
                $lanes[] = [$bar];
                $laneEnds[] = $bar->endIndex();
            }
        }

        return $lanes;
    }
}
