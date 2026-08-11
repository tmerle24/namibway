<?php

namespace App\Services\Inventory;

use App\Enums\PricingStrategy;
use App\Exceptions\Inventory\StayRuleViolationException;
use App\Exceptions\Pricing\UnpriceableStayException;
use App\Models\BookableUnit;
use App\Models\BookableUnitCalendarDay;
use App\Models\BookingSlot;
use App\Models\GuestCategory;
use App\Models\RatePlan;
use App\Models\RatePlanDay;
use App\Models\RatePlanGuestAmount;
use App\Services\Inventory\DTOs\CalendarSnapshot;
use App\Services\Inventory\DTOs\NightlyRate;
use App\Services\Inventory\DTOs\Quote;
use App\Services\Pricing\NightPricingContext;
use App\Services\Pricing\Occupancy;
use App\Services\Pricing\Pricers;
use App\Services\Pricing\PricingConfig;
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
 *
 * ## Rate plans
 *
 * Availability is read without one — a room is sold once however many plans it
 * is offered under. Anything about money or restrictions takes an optional
 * rate plan, and **null means the property's default plan**, falling back to
 * the room type's own rate where a property has defined none — which is the
 * behaviour before rate plans existed. A caller that knows which product it is
 * selling names it; one that just wants "the price of this room" does not have
 * to know rate plans exist.
 *
 * snapshot() is the exception and takes the plan explicitly: it is handed room
 * types rather than a property, so there is nothing for it to resolve against.
 */
class AvailabilityCalendar
{
    /**
     * Units of this room type free on one night: capacity, minus what is
     * sold, minus what is blocked.
     */
    public function unitsFree(BookableUnit $bookableUnit, CarbonInterface $date): int
    {
        $day = $this->day($bookableUnit, $date);

        return $this->capacityFrom($bookableUnit, $day) - $this->occupiedFrom($day);
    }

    /**
     * Seats free on one departure.
     *
     * A departure keeps its own row and its own counter, so this is the same
     * question as unitsFree() asked of a different row — the 09:00 tour
     * selling out says nothing about the 14:00 one. Capacity falls back to the
     * unit's own total, exactly as it does for a night: a sparse calendar
     * means "as many as the unit has".
     */
    /**
     * What one seat on a departure costs.
     *
     * Three steps, and every one of them already existed: the departure's own
     * rate where the plan sets one, the day's rate where it does not, and the
     * unit's own rate where neither does. A property that has never heard of
     * departures reads step two, which is exactly what it read before.
     */
    public function rateForSlot(BookableUnit $bookableUnit, CarbonInterface $date, BookingSlot $slot, ?RatePlan $ratePlan = null): float
    {
        $plan = $this->planFor($bookableUnit, $ratePlan);

        if ($plan !== null) {
            $forDeparture = RatePlanDay::query()
                ->where('rate_plan_id', $plan->id)
                ->where('bookable_unit_id', $bookableUnit->id)
                ->where('slot_id', $slot->id)
                ->whereDate('date', $date->toDateString())
                ->whereNotNull('rate')
                ->value('rate');

            if ($forDeparture !== null) {
                return round((float) $forDeparture, 2);
            }
        }

        return $this->rateFor($bookableUnit, $date, $ratePlan);
    }

    public function seatsFree(BookableUnit $bookableUnit, CarbonInterface $date, BookingSlot $slot): int
    {
        $day = BookableUnitCalendarDay::query()
            ->where('bookable_unit_id', $bookableUnit->id)
            ->where('slot_id', $slot->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        return $this->capacityFrom($bookableUnit, $day) - $this->occupiedFrom($day);
    }

    /**
     * Seats free on every date a departure line covers, keyed by Y-m-d.
     *
     * A line spanning more than one date is a repeated ride — the writer takes
     * a seat on the departure once per date, which is what it already does for
     * a night — so the question is the same one asked of each date, and what
     * limits the line is the worst of them.
     *
     * @return array<string, int>
     */
    public function seatsFreeAcross(BookableUnit $bookableUnit, BookingSlot $slot, CarbonInterface $checkIn, CarbonInterface $checkOut): array
    {
        $rows = BookableUnitCalendarDay::query()
            ->where('bookable_unit_id', $bookableUnit->id)
            ->where('slot_id', $slot->id)
            ->whereBetween('date', [
                Carbon::parse($checkIn)->toDateString(),
                Carbon::parse($checkOut)->toDateString(),
            ])
            ->get()
            ->keyBy(fn (BookableUnitCalendarDay $day) => $day->date->toDateString());

        $free = [];

        foreach ($this->nights($checkIn, $checkOut) as $date) {
            $key = $date->toDateString();
            $day = $rows->get($key);

            $free[$key] = $this->capacityFrom($bookableUnit, $day) - $this->occupiedFrom($day);
        }

        return $free;
    }

    /** The fullest departure of the ones a line covers — what it is limited by. */
    public function seatsFreeThroughout(BookableUnit $bookableUnit, BookingSlot $slot, CarbonInterface $checkIn, CarbonInterface $checkOut): int
    {
        $free = $this->seatsFreeAcross($bookableUnit, $slot, $checkIn, $checkOut);

        return $free === [] ? 0 : (int) min($free);
    }

    /**
     * Units free on every night of a stay, keyed by Y-m-d. The check-out day
     * is not a night and is not included.
     *
     * @return array<string, int>
     */
    public function unitsFreeAcross(BookableUnit $bookableUnit, CarbonInterface $checkIn, CarbonInterface $checkOut): array
    {
        $days = $this->daysKeyedByDate($bookableUnit, $checkIn, $checkOut);
        $free = [];

        foreach ($this->nights($checkIn, $checkOut) as $night) {
            $day = $days[$night->toDateString()] ?? null;
            $free[$night->toDateString()] = $this->capacityFrom($bookableUnit, $day) - $this->occupiedFrom($day);
        }

        return $free;
    }

    /**
     * The fewest units free on any night of the stay — what a booking of that
     * whole range is actually limited by.
     */
    public function unitsFreeThroughout(BookableUnit $bookableUnit, CarbonInterface $checkIn, CarbonInterface $checkOut): int
    {
        $free = $this->unitsFreeAcross($bookableUnit, $checkIn, $checkOut);

        return $free === [] ? 0 : (int) min($free);
    }

    /**
     * The rate for one night: what the plan says for that night where it says
     * anything, the room type's own rate otherwise. A pure function of (room
     * type, date, plan) — which is exactly why seasons are written onto dates
     * rather than resolved at read time.
     *
     * This is the *base* number, which is what a calendar cell shows and what
     * a lodge edits. Under a per-person plan it is the price of one person
     * sharing, not of the room — what a stay actually costs comes out of
     * quote(), where the strategy has the occupancy to work with.
     */
    public function rateFor(BookableUnit $bookableUnit, CarbonInterface $date, ?RatePlan $ratePlan = null): float
    {
        return $this->rateFrom($bookableUnit, $this->rateDay($bookableUnit, $date, $ratePlan));
    }

    public function capacityFor(BookableUnit $bookableUnit, CarbonInterface $date): int
    {
        return $this->capacityFrom($bookableUnit, $this->day($bookableUnit, $date));
    }

    /**
     * Price a stay night by night. `units` multiplies each night rather than
     * the total, so a quote stays correct if a line's quantity ever varies
     * across the stay.
     *
     * What one night costs is the rate plan's strategy to decide — per room,
     * per number of guests, or per person sharing. This method gathers what
     * the calculation needs and stays out of it; see App\Services\Pricing.
     *
     * @throws UnpriceableStayException when the strategy cannot price a night
     */
    public function quote(
        BookableUnit $bookableUnit,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $units = 1,
        ?RatePlan $ratePlan = null,
        ?Occupancy $occupancy = null,
        ?BookingSlot $slot = null,
    ): Quote {
        $ratePlan = $this->planFor($bookableUnit, $ratePlan);
        $strategy = $ratePlan->pricing_strategy ?? PricingStrategy::PerUnit;
        $pricer = Pricers::for($strategy);

        $occupancy ??= Occupancy::empty();
        $config = $ratePlan?->pricingConfig() ?? PricingConfig::empty();
        $days = $this->rateDaysKeyedByDate($bookableUnit, $checkIn, $checkOut, $ratePlan);
        $categories = $strategy->needsOccupancy() ? $this->guestCategories($bookableUnit) : [];
        $amounts = $strategy === PricingStrategy::PerOccupancy
            ? $this->guestAmounts($bookableUnit, $checkIn, $checkOut, $ratePlan)
            : [];

        $this->assertFits($bookableUnit, $occupancy, $categories);

        $nights = [];

        foreach ($this->nights($checkIn, $checkOut) as $night) {
            $key = $night->toDateString();

            $nights[] = new NightlyRate(
                date: $night,
                rate: $pricer->price(new NightPricingContext(
                    bookableUnit: $bookableUnit,
                    date: $night,
                    ratePlan: $ratePlan,
                    // A departure's own rate where the plan sets one, and the
                    // day's rate where it does not — which is every rate a
                    // lodge has ever entered, read exactly as before.
                    baseRate: $slot === null
                        ? $this->rateFrom($bookableUnit, $days[$key] ?? null)
                        : $this->rateForSlot($bookableUnit, $night, $slot, $ratePlan),
                    occupancy: $occupancy,
                    categories: $categories,
                    guestAmounts: $amounts[$key] ?? [],
                    config: $config,
                )),
                units: $units,
            );
        }

        return new Quote($nights, CountrySettings::currencyForBookableUnit($bookableUnit));
    }

    /**
     * How many people the room sleeps, checked before anything is priced.
     *
     * The strategies would otherwise happily price six people into a double —
     * per-person-sharing by multiplying, per-occupancy by refusing for the
     * wrong reason ("no price for 6 guests" rather than "it sleeps 2").
     *
     * @param  array<int, GuestCategory>  $categories
     *
     * @throws UnpriceableStayException
     */
    private function assertFits(BookableUnit $bookableUnit, Occupancy $occupancy, array $categories): void
    {
        if ($categories === [] || $occupancy->isEmpty()) {
            return;
        }

        $capacity = $bookableUnit->max_adults + $bookableUnit->max_children;

        // A room type that has never had its capacity filled in says nothing,
        // and refusing every booking of it would be worse than not checking.
        if ($capacity < 1) {
            return;
        }

        $guests = $occupancy->occupancyCount($categories);

        if ($guests > $capacity) {
            throw UnpriceableStayException::tooManyGuests($bookableUnit->name, $guests, $capacity);
        }
    }

    /**
     * The property's guest categories, keyed by id for the pricers.
     *
     * @return array<int, GuestCategory>
     */
    private function guestCategories(BookableUnit $bookableUnit): array
    {
        $listing = $bookableUnit->listing;

        if ($listing === null) {
            return [];
        }

        $categories = [];

        foreach (GuestCategory::forListing($listing) as $category) {
            $categories[$category->id] = $category;
        }

        return $categories;
    }

    /**
     * Amounts by guest count for every night of the stay, in one query.
     *
     * @return array<string, array<int, float>> Y-m-d => guests => amount
     */
    private function guestAmounts(
        BookableUnit $bookableUnit,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?RatePlan $ratePlan,
    ): array {
        if ($ratePlan === null) {
            return [];
        }

        $rows = RatePlanGuestAmount::query()
            ->where('rate_plan_id', $ratePlan->id)
            ->where('bookable_unit_id', $bookableUnit->id)
            ->whereBetween('date', [
                Carbon::parse($checkIn)->toDateString(),
                Carbon::parse($checkOut)->toDateString(),
            ])
            ->get();

        $amounts = [];

        foreach ($rows as $row) {
            $amounts[$row->date->toDateString()][$row->guests] = $row->amount;
        }

        return $amounts;
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
    public function assertStayRules(
        BookableUnit $bookableUnit,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?RatePlan $ratePlan = null,
    ): void {
        $checkInDate = Carbon::parse($checkIn)->startOfDay();
        $checkOutDate = Carbon::parse($checkOut)->startOfDay();
        $nights = (int) $checkInDate->diffInDays($checkOutDate);

        $arrival = $this->rateDay($bookableUnit, $checkInDate, $ratePlan);

        if ($arrival?->closed_to_arrival === true) {
            throw StayRuleViolationException::closedToArrival($checkInDate->toDateString());
        }

        // Closed-to-departure is a rule about the day the guest leaves, which
        // is the check-out date itself and not one of the booked nights.
        $departure = $this->rateDay($bookableUnit, $checkOutDate, $ratePlan);

        if ($departure?->closed_to_departure === true) {
            throw StayRuleViolationException::closedToDeparture($checkOutDate->toDateString());
        }

        $minStay = $arrival?->min_stay;

        if ($minStay !== null && $nights < $minStay) {
            throw StayRuleViolationException::minStay($minStay, $nights);
        }
    }

    public function passesStayRules(
        BookableUnit $bookableUnit,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?RatePlan $ratePlan = null,
    ): bool {
        try {
            $this->assertStayRules($bookableUnit, $checkIn, $checkOut, $ratePlan);
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
     * @param  iterable<int, BookableUnit>  $bookableUnits
     */
    public function snapshot(
        iterable $bookableUnits,
        CarbonInterface $from,
        CarbonInterface $to,
        ?RatePlan $ratePlan = null,
    ): CalendarSnapshot {
        $keyed = [];

        foreach ($bookableUnits as $bookableUnit) {
            $keyed[$bookableUnit->id] = $bookableUnit;
        }

        $days = [];
        $slotDays = [];

        if ($keyed !== []) {
            $rows = BookableUnitCalendarDay::query()
                ->whereIn('bookable_unit_id', array_keys($keyed))
                ->whereDate('date', '>=', Carbon::parse($from)->toDateString())
                ->whereDate('date', '<', Carbon::parse($to)->toDateString())
                ->get();

            // Both kinds of row come back in the one query — they are the same
            // table — and are then filed apart. Filing them together is not a
            // cosmetic mistake: two rows share a date, so whichever arrived
            // last would silently become the day, and a tour operator's grid
            // would report the last departure's counters as the property's.
            foreach ($rows as $row) {
                if ($row->slot_id === null) {
                    $days[$row->bookable_unit_id][$row->date->toDateString()] = $row;
                } else {
                    $slotDays[$row->bookable_unit_id][$row->date->toDateString()][$row->slot_id] = $row;
                }
            }
        }

        $rateDays = [];
        $slotRateDays = [];

        if ($keyed !== [] && $ratePlan !== null) {
            $rows = RatePlanDay::query()
                ->where('rate_plan_id', $ratePlan->id)
                ->whereIn('bookable_unit_id', array_keys($keyed))
                ->whereDate('date', '>=', Carbon::parse($from)->toDateString())
                ->whereDate('date', '<', Carbon::parse($to)->toDateString())
                ->get();

            foreach ($rows as $row) {
                if ($row->slot_id === null) {
                    $rateDays[$row->bookable_unit_id][$row->date->toDateString()] = $row;
                } else {
                    $slotRateDays[$row->bookable_unit_id][$row->date->toDateString()][$row->slot_id] = $row;
                }
            }
        }

        return new CalendarSnapshot($days, $keyed, $rateDays, $slotDays, $slotRateDays);
    }

    /**
     * The plan to read when the caller named none: the property's default.
     * Null comes back only where a property has no plan at all, and that reads
     * as the room type's own rate.
     */
    private function planFor(BookableUnit $bookableUnit, ?RatePlan $ratePlan): ?RatePlan
    {
        if ($ratePlan !== null) {
            return $ratePlan;
        }

        $listing = $bookableUnit->listing;

        return $listing === null ? null : RatePlan::defaultFor($listing);
    }

    /** One night's rate row, or null when the plan — or the property — has none. */
    private function rateDay(BookableUnit $bookableUnit, CarbonInterface $date, ?RatePlan $ratePlan): ?RatePlanDay
    {
        $ratePlan = $this->planFor($bookableUnit, $ratePlan);

        if ($ratePlan === null) {
            return null;
        }

        return RatePlanDay::query()
            ->where('rate_plan_id', $ratePlan->id)
            ->where('bookable_unit_id', $bookableUnit->id)
            // The day's own rate, never a departure's. Without this a tour
            // operator's 14:00 price would answer the question "what does this
            // day cost" — and, worse, would answer it for the 09:00 departure
            // too, since that one falls back to the day.
            ->whereNull('slot_id')
            ->whereDate('date', Carbon::parse($date)->toDateString())
            ->first();
    }

    /**
     * @return array<string, RatePlanDay>
     */
    private function rateDaysKeyedByDate(
        BookableUnit $bookableUnit,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?RatePlan $ratePlan,
    ): array {
        $ratePlan = $this->planFor($bookableUnit, $ratePlan);

        if ($ratePlan === null) {
            return [];
        }

        return RatePlanDay::query()
            ->where('rate_plan_id', $ratePlan->id)
            ->where('bookable_unit_id', $bookableUnit->id)
            // Nights, so the day's rows. A stay priced by the night on a unit
            // that also runs departures must not pick up a departure's price
            // — and keyBy() would have let the last one written win.
            ->whereNull('slot_id')
            ->whereBetween('date', [
                Carbon::parse($checkIn)->toDateString(),
                Carbon::parse($checkOut)->toDateString(),
            ])
            ->get()
            ->keyBy(fn (RatePlanDay $day) => $day->date->toDateString())
            ->all();
    }

    /**
     * The busiest night in a range: the most units already sold or blocked on
     * any one of them.
     *
     * This is what capacity may not be lowered below. The database refuses it
     * too — there is a CHECK constraint — but a Postgres constraint violation
     * is not something to show a lodge manager who was setting rates for a
     * season, so the screen asks first and can name the night.
     *
     * `$to` is inclusive here, because a bulk calendar edit counts nights.
     *
     * @return array{units: int, date: Carbon|null}
     */
    public function busiestNight(BookableUnit $bookableUnit, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = BookableUnitCalendarDay::query()
            ->where('bookable_unit_id', $bookableUnit->id)
            ->whereDate('date', '>=', Carbon::parse($from)->toDateString())
            ->whereDate('date', '<=', Carbon::parse($to)->toDateString())
            ->get();

        $busiest = 0;
        $when = null;

        foreach ($rows as $row) {
            $occupied = CalendarSnapshot::occupiedOn($row);

            if ($occupied > $busiest) {
                $busiest = $occupied;
                $when = Carbon::parse($row->date)->startOfDay();
            }
        }

        return ['units' => $busiest, 'date' => $when];
    }

    private function day(BookableUnit $bookableUnit, CarbonInterface $date): ?BookableUnitCalendarDay
    {
        return BookableUnitCalendarDay::query()
            ->where('bookable_unit_id', $bookableUnit->id)
            // The day's own row. A departure keeps its own counter beside it,
            // and reading one as the other would make a full 09:00 tour look
            // like a full property.
            ->whereNull('slot_id')
            ->whereDate('date', Carbon::parse($date)->toDateString())
            ->first();
    }

    /**
     * @return array<string, BookableUnitCalendarDay>
     */
    private function daysKeyedByDate(BookableUnit $bookableUnit, CarbonInterface $checkIn, CarbonInterface $checkOut): array
    {
        return BookableUnitCalendarDay::query()
            ->where('bookable_unit_id', $bookableUnit->id)
            ->whereNull('slot_id')
            ->whereBetween('date', [
                Carbon::parse($checkIn)->toDateString(),
                Carbon::parse($checkOut)->toDateString(),
            ])
            ->get()
            ->keyBy(fn (BookableUnitCalendarDay $day) => $day->date->toDateString())
            ->all();
    }

    /**
     * The sparse-calendar rules — a missing row or a null override means
     * "follow the room type" — live on CalendarSnapshot, so the night-by-night
     * reads here and the bulk read a grid uses cannot drift apart.
     */
    private function capacityFrom(BookableUnit $bookableUnit, ?BookableUnitCalendarDay $day): int
    {
        return CalendarSnapshot::capacityFor($bookableUnit, $day);
    }

    private function rateFrom(BookableUnit $bookableUnit, ?RatePlanDay $day): float
    {
        return CalendarSnapshot::rateFor($bookableUnit, $day);
    }

    private function occupiedFrom(?BookableUnitCalendarDay $day): int
    {
        return CalendarSnapshot::occupiedOn($day);
    }
}
