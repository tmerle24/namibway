<?php

namespace App\Services\Inventory;

use App\Exceptions\Inventory\StayRuleViolationException;
use App\Models\RoomType;
use App\Models\RoomTypeCalendarDay;
use App\Services\Inventory\DTOs\CalendarSnapshot;
use App\Services\Inventory\DTOs\NightlyRate;
use App\Services\Inventory\DTOs\Quote;
use App\Support\CountrySettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Reads the ARI calendar: how many units are free on a night, what a night
 * costs, and whether a stay is allowed by the restrictions on those nights.
 *
 * **This is not App\Services\Booking\RoomAvailability and must not be merged
 * with it.** That one answers the traveller-facing question from overlapping
 * Inquiry rows and is the source of truth for the trip plan's room picker.
 * This one answers the lodge-facing question from the calendar. They will
 * disagree, on purpose, until the deliberate later step of pointing the
 * traveller-facing picker at the calendar — see CLAUDE.md, "Standards".
 *
 * Everything here is a read. Nothing in this class writes a row, including
 * the calendar rows it finds missing: a sparse calendar is the normal state,
 * not a gap to repair.
 */
class AvailabilityCalendar
{
    /**
     * Units of this room type free on one night: capacity, minus what is
     * sold, minus what is blocked.
     */
    public function unitsFree(RoomType $roomType, CarbonInterface $date): int
    {
        $day = $this->day($roomType, $date);

        return $this->capacityFrom($roomType, $day) - $this->occupiedFrom($day);
    }

    /**
     * Units free on every night of a stay, keyed by Y-m-d. The check-out day
     * is not a night and is not included.
     *
     * @return array<string, int>
     */
    public function unitsFreeAcross(RoomType $roomType, CarbonInterface $checkIn, CarbonInterface $checkOut): array
    {
        $days = $this->daysKeyedByDate($roomType, $checkIn, $checkOut);
        $free = [];

        foreach ($this->nights($checkIn, $checkOut) as $night) {
            $day = $days[$night->toDateString()] ?? null;
            $free[$night->toDateString()] = $this->capacityFrom($roomType, $day) - $this->occupiedFrom($day);
        }

        return $free;
    }

    /**
     * The fewest units free on any night of the stay — what a booking of that
     * whole range is actually limited by.
     */
    public function unitsFreeThroughout(RoomType $roomType, CarbonInterface $checkIn, CarbonInterface $checkOut): int
    {
        $free = $this->unitsFreeAcross($roomType, $checkIn, $checkOut);

        return $free === [] ? 0 : (int) min($free);
    }

    /**
     * The rate for one night: the calendar's override where there is one, the
     * room type's own rate otherwise. A pure function of (room type, date) —
     * which is exactly why seasons are written onto dates rather than
     * resolved at read time.
     */
    public function rateFor(RoomType $roomType, CarbonInterface $date): float
    {
        return $this->rateFrom($roomType, $this->day($roomType, $date));
    }

    public function capacityFor(RoomType $roomType, CarbonInterface $date): int
    {
        return $this->capacityFrom($roomType, $this->day($roomType, $date));
    }

    /**
     * Price a stay night by night. `units` multiplies each night rather than
     * the total, so a quote stays correct if a line's quantity ever varies
     * across the stay.
     */
    public function quote(RoomType $roomType, CarbonInterface $checkIn, CarbonInterface $checkOut, int $units = 1): Quote
    {
        $days = $this->daysKeyedByDate($roomType, $checkIn, $checkOut);
        $nights = [];

        foreach ($this->nights($checkIn, $checkOut) as $night) {
            $day = $days[$night->toDateString()] ?? null;

            $nights[] = new NightlyRate(
                date: $night,
                rate: $this->rateFrom($roomType, $day),
                units: $units,
            );
        }

        return new Quote($nights, CountrySettings::currencyForRoomType($roomType));
    }

    /**
     * Whether the restrictions on these nights permit the stay.
     *
     * Minimum stay is read from the arrival night, which is the convention
     * every channel manager uses: it is a condition of arriving that day, not
     * a property of the range.
     *
     * @throws StayRuleViolationException
     */
    public function assertStayRules(RoomType $roomType, CarbonInterface $checkIn, CarbonInterface $checkOut): void
    {
        $checkInDate = Carbon::parse($checkIn)->startOfDay();
        $checkOutDate = Carbon::parse($checkOut)->startOfDay();
        $nights = (int) $checkInDate->diffInDays($checkOutDate);

        $arrival = $this->day($roomType, $checkInDate);

        if ($arrival?->closed_to_arrival === true) {
            throw StayRuleViolationException::closedToArrival($checkInDate->toDateString());
        }

        // Closed-to-departure is a rule about the day the guest leaves, which
        // is the check-out date itself and not one of the booked nights.
        $departure = $this->day($roomType, $checkOutDate);

        if ($departure?->closed_to_departure === true) {
            throw StayRuleViolationException::closedToDeparture($checkOutDate->toDateString());
        }

        $minStay = $arrival?->min_stay;

        if ($minStay !== null && $nights < $minStay) {
            throw StayRuleViolationException::minStay($minStay, $nights);
        }
    }

    public function passesStayRules(RoomType $roomType, CarbonInterface $checkIn, CarbonInterface $checkOut): bool
    {
        try {
            $this->assertStayRules($roomType, $checkIn, $checkOut);
        } catch (StayRuleViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Every night of a stay. Half-open: the 5th to the 8th is the 5th, 6th
     * and 7th.
     *
     * @return array<int, Carbon>
     */
    public function nights(CarbonInterface $checkIn, CarbonInterface $checkOut): array
    {
        $night = Carbon::parse($checkIn)->startOfDay();
        $end = Carbon::parse($checkOut)->startOfDay();
        $nights = [];

        while ($night->lt($end)) {
            $nights[] = $night->copy();
            // Reassigned rather than mutated in place: the app runs on
            // CarbonImmutable (AppServiceProvider), and a mutating addDay()
            // that silently returns a new instance would loop forever.
            $night = $night->addDay();
        }

        return $nights;
    }

    /**
     * The whole calendar for many room types across a range, in one query.
     *
     * The single-night reads above are per room type by design — a booking
     * asks about one. A grid asks about all of them for a month, and asking
     * night by night would be one query per cell. Both answers come out of the
     * same rules; see CalendarSnapshot.
     *
     * `$to` is exclusive, matching nights() and the half-open stay convention.
     *
     * @param  iterable<int, RoomType>  $roomTypes
     */
    public function snapshot(iterable $roomTypes, CarbonInterface $from, CarbonInterface $to): CalendarSnapshot
    {
        $keyed = [];

        foreach ($roomTypes as $roomType) {
            $keyed[$roomType->id] = $roomType;
        }

        $days = [];

        if ($keyed !== []) {
            $rows = RoomTypeCalendarDay::query()
                ->whereIn('room_type_id', array_keys($keyed))
                ->whereDate('date', '>=', Carbon::parse($from)->toDateString())
                ->whereDate('date', '<', Carbon::parse($to)->toDateString())
                ->get();

            foreach ($rows as $row) {
                $days[$row->room_type_id][$row->date->toDateString()] = $row;
            }
        }

        return new CalendarSnapshot($days, $keyed);
    }

    private function day(RoomType $roomType, CarbonInterface $date): ?RoomTypeCalendarDay
    {
        return RoomTypeCalendarDay::query()
            ->where('room_type_id', $roomType->id)
            ->whereDate('date', Carbon::parse($date)->toDateString())
            ->first();
    }

    /**
     * @return array<string, RoomTypeCalendarDay>
     */
    private function daysKeyedByDate(RoomType $roomType, CarbonInterface $checkIn, CarbonInterface $checkOut): array
    {
        return RoomTypeCalendarDay::query()
            ->where('room_type_id', $roomType->id)
            ->whereBetween('date', [
                Carbon::parse($checkIn)->toDateString(),
                Carbon::parse($checkOut)->toDateString(),
            ])
            ->get()
            ->keyBy(fn (RoomTypeCalendarDay $day) => $day->date->toDateString())
            ->all();
    }

    /**
     * The sparse-calendar rules — a missing row or a null override means
     * "follow the room type" — live on CalendarSnapshot, so the night-by-night
     * reads here and the bulk read a grid uses cannot drift apart.
     */
    private function capacityFrom(RoomType $roomType, ?RoomTypeCalendarDay $day): int
    {
        return CalendarSnapshot::capacityFor($roomType, $day);
    }

    private function rateFrom(RoomType $roomType, ?RoomTypeCalendarDay $day): float
    {
        return CalendarSnapshot::rateFor($roomType, $day);
    }

    private function occupiedFrom(?RoomTypeCalendarDay $day): int
    {
        return CalendarSnapshot::occupiedOn($day);
    }
}
