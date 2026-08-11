<?php

namespace App\Services\Inventory;

use App\Enums\StayStatus;
use App\Models\BookableUnit;
use App\Models\BookableUnitCalendarDay;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\RatePlanDay;
use App\Models\ReservationUnit;
use App\Services\Inventory\DTOs\CalendarSnapshot;
use App\Services\Inventory\DTOs\DayGridData;
use App\Services\Inventory\DTOs\DepartureColumn;
use App\Services\Inventory\DTOs\DepartureStay;
use App\Support\CountrySettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * One day read the other way round: the hour axis down the page, the
 * property's departures across it.
 *
 * **Read only**, like OccupancyGrid, and off the same rows — the counters here
 * are `bookable_unit_calendar_days`, the same table and the same conditional
 * UPDATE that resolves two people racing for the last room. What differs is
 * only which of those rows are asked for: the ones keyed to a departure.
 *
 * ## Why this is a second class and not OccupancyGrid transposed
 *
 * BOOKING_SYSTEM.md left the question open to answer against a real grid, so
 * here is the answer, and it is not the tidy one.
 *
 * A night grid's second axis is a **series of counters**: every (unit, night)
 * pair has one, every cell is populated, and a stay is drawn *over* those cells
 * as a bar spanning some of them. An hour axis carries no counters at all. It
 * is a ruler — the only thing on it is a departure block positioned against it,
 * and a fifteen-minute axis over eight departures would be 96 × 8 positions of
 * which eight mean anything.
 *
 * Transposing the one component would therefore have to invent an empty cell
 * for every (time step, departure) pair, which is decision 1 of "Time inside a
 * day" — the grid is drawing, the slot is inventory — re-committed in the view
 * layer, one abstraction down from the table it was banned from. The other
 * direction is no better: making a departure "a cell with a span" would put a
 * concept into the night grid that no night has.
 *
 * What the doc insisted on either way is upheld and is the part that matters:
 * **the rows underneath are the same rows.** Both readings go through the same
 * sparse-calendar rules on CalendarSnapshot, so a property selling a chalet and
 * a sunset drive has one calendar seen twice, not two calendars.
 */
class DayGrid
{
    /**
     * The resolutions a day can be drawn at. Fifteen is the finest anyone
     * schedules by here, and sixty is what a property with hourly departures
     * wants; anything between the two is one of these.
     *
     * @var array<int, int>
     */
    public const RESOLUTIONS = [60, 30, 15];

    /**
     * Null when the property runs no departures at all — which is every
     * accommodation property, and is why nothing about this class can reach a
     * lodge's screen.
     */
    public function build(
        Listing $listing,
        CarbonInterface $date,
        ?RatePlan $ratePlan = null,
        ?int $resolutionMinutes = null,
    ): ?DayGridData {
        $ratePlan ??= RatePlan::defaultFor($listing);
        $day = Carbon::parse($date)->startOfDay();

        $units = $listing->bookableUnits()->orderBy('name')->get()->keyBy('id');

        if ($units->isEmpty()) {
            return null;
        }

        $slots = BookingSlot::query()
            ->whereIn('bookable_unit_id', $units->keys()->all())
            ->where('is_active', true)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        if ($slots->isEmpty()) {
            return null;
        }

        $slotIds = $slots->pluck('id')->all();

        $inventory = $this->inventory($slotIds, $day);

        [$slotRates, $dayRates] = $this->rates($ratePlan, $units->keys()->all(), $slotIds, $day);
        $stays = $this->stays($listing, $slotIds, $day);

        $resolution = $this->resolution($slots, $resolutionMinutes);
        [$axisStartMinutes, $axisEndMinutes] = $this->axis($slots);
        $rowCount = intdiv($axisEndMinutes - $axisStartMinutes, $resolution);

        $columns = [];

        foreach ($slots as $slot) {
            $unit = $units->get($slot->bookable_unit_id);

            if (! $unit instanceof BookableUnit) {
                continue;
            }

            $row = $inventory[$slot->id] ?? null;
            $sold = $row === null ? 0 : $row->units_sold;
            $blocked = $row === null ? 0 : $row->units_blocked;

            // A retired unit is noise unless its day still has something in
            // it — the same rule the night grid applies to a room type.
            if (! $unit->is_active && $sold === 0 && $blocked === 0) {
                continue;
            }

            $capacity = CalendarSnapshot::capacityFor($unit, $row);
            $startMinutes = $this->minutes($slot->starts_at);
            $endMinutes = $startMinutes + $slot->duration_minutes;

            $startIndex = intdiv($startMinutes - $axisStartMinutes, $resolution);
            $span = max(1, (int) ceil(($endMinutes - $startMinutes) / $resolution));

            $columns[] = new DepartureColumn(
                unit: $unit,
                slot: $slot,
                startsAt: $day->copy()->addMinutes($startMinutes),
                endsAt: $day->copy()->addMinutes($endMinutes),
                startIndex: $startIndex,
                span: max(1, min($span, $rowCount - $startIndex)),
                clippedEnd: $endMinutes > $axisEndMinutes,
                capacity: $capacity,
                seatsSold: $sold,
                seatsBlocked: $blocked,
                seatsFree: $capacity - $sold - $blocked,
                rate: CalendarSnapshot::rateFor(
                    $unit,
                    $slotRates[$slot->id] ?? $dayRates[$unit->id] ?? null,
                ),
                currency: CountrySettings::currencyForBookableUnit($unit),
                stays: $stays[$slot->id] ?? [],
            );
        }

        if ($columns === []) {
            return null;
        }

        return new DayGridData(
            listing: $listing,
            date: $day,
            axisStart: $day->copy()->addMinutes($axisStartMinutes),
            resolutionMinutes: $resolution,
            rowCount: $rowCount,
            columns: $columns,
            currency: CountrySettings::for($listing)->currency(),
        );
    }

    /**
     * How fine to draw the day.
     *
     * Derived from the timetable rather than configured: the coarsest step that
     * still lands every departure's start and end on a line is the one that
     * draws the day truthfully with the fewest rows, and a property whose tours
     * all run on the hour has nothing to configure. An operator can still say
     * otherwise, and anything they say that is not one of the three is ignored
     * rather than refused — it arrives from a query string.
     *
     * @param  iterable<int, BookingSlot>  $slots
     */
    private function resolution(iterable $slots, ?int $requested): int
    {
        if ($requested !== null && in_array($requested, self::RESOLUTIONS, true)) {
            return $requested;
        }

        foreach (self::RESOLUTIONS as $step) {
            $fits = true;

            foreach ($slots as $slot) {
                if ($this->minutes($slot->starts_at) % $step !== 0 || $slot->duration_minutes % $step !== 0) {
                    $fits = false;

                    break;
                }
            }

            if ($fits) {
                return $step;
            }
        }

        return 15;
    }

    /**
     * The window the axis covers, in minutes from midnight: whole hours around
     * the first departure and the last one to end.
     *
     * Capped at midnight. A drive that finishes after it is drawn cut off at
     * the bottom rather than spilling into a day it does not belong to — its
     * seats came off the date it left on, and this is a picture of that date.
     *
     * Both ends land on a whole hour, which is what keeps the axis divisible by
     * every resolution on offer: 15, 30 and 60 all go into 60.
     *
     * @param  iterable<int, BookingSlot>  $slots
     * @return array{int, int}
     */
    private function axis(iterable $slots): array
    {
        $earliest = null;
        $latest = null;

        foreach ($slots as $slot) {
            $start = $this->minutes($slot->starts_at);
            $end = $start + $slot->duration_minutes;

            $earliest = $earliest === null ? $start : min($earliest, $start);
            $latest = $latest === null ? $end : max($latest, $end);
        }

        $start = intdiv((int) $earliest, 60) * 60;
        $end = min(1440, (int) ceil(((int) $latest) / 60) * 60);

        return [$start, max($end, $start + 60)];
    }

    /**
     * Every departure's inventory row for the date, keyed by slot.
     *
     * @param  array<int, int>  $slotIds
     * @return array<int, BookableUnitCalendarDay>
     */
    private function inventory(array $slotIds, Carbon $day): array
    {
        $rows = [];

        foreach (BookableUnitCalendarDay::query()->whereIn('slot_id', $slotIds)->whereDate('date', $day->toDateString())->get() as $row) {
            if ($row->slot_id !== null) {
                $rows[$row->slot_id] = $row;
            }
        }

        return $rows;
    }

    /**
     * The bookings sitting on each departure, keyed by slot.
     *
     * @param  array<int, int>  $slotIds
     * @return array<int, array<int, DepartureStay>>
     */
    private function stays(Listing $listing, array $slotIds, Carbon $day): array
    {
        if ($slotIds === []) {
            return [];
        }

        $units = ReservationUnit::query()
            ->whereIn('slot_id', $slotIds)
            ->whereDate('check_in', '<=', $day->toDateString())
            ->whereDate('check_out', '>', $day->toDateString())
            ->whereHas('reservation', fn (Builder $query) => $query
                ->where('listing_id', $listing->id)
                ->whereIn('status', array_map(fn (StayStatus $status) => $status->value, StayStatus::occupying())))
            ->with('reservation')
            ->orderBy('id')
            ->get();

        $stays = [];

        foreach ($units as $unit) {
            $reservation = $unit->reservation;

            if ($reservation === null || $unit->slot_id === null) {
                continue;
            }

            $stays[$unit->slot_id][] = new DepartureStay(
                reservationId: $reservation->id,
                label: $reservation->guest_name,
                seats: $unit->quantity,
                state: $reservation->status,
                reference: $reservation->reference,
                phone: $reservation->guest_phone,
                party: $reservation->adults + $reservation->children,
            );
        }

        return $stays;
    }

    /**
     * Rate rows for the date, split the way the fallback chain reads them: a
     * departure's own price where the plan set one, the day's price where it
     * did not.
     *
     * @param  array<int, int>  $bookableUnitIds
     * @param  array<int, int>  $slotIds
     * @return array{array<int, RatePlanDay>, array<int, RatePlanDay>}
     */
    private function rates(?RatePlan $ratePlan, array $bookableUnitIds, array $slotIds, Carbon $day): array
    {
        if ($ratePlan === null || $bookableUnitIds === []) {
            return [[], []];
        }

        $rows = RatePlanDay::query()
            ->where('rate_plan_id', $ratePlan->id)
            ->whereIn('bookable_unit_id', $bookableUnitIds)
            ->whereDate('date', $day->toDateString())
            ->where(fn (Builder $query) => $query->whereNull('slot_id')->orWhereIn('slot_id', $slotIds))
            ->get();

        $slotRates = [];
        $dayRates = [];

        foreach ($rows as $row) {
            if ($row->slot_id === null) {
                $dayRates[$row->bookable_unit_id] = $row;
            } else {
                $slotRates[$row->slot_id] = $row;
            }
        }

        return [$slotRates, $dayRates];
    }

    /** "09:00:00" as minutes from midnight. */
    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $hours * 60 + $minutes;
    }
}
