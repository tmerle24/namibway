<?php

namespace App\Services\Booking;

use App\Enums\GuestKind;
use App\Exceptions\Pricing\UnpriceableStayException;
use App\Models\BookableUnit;
use App\Models\BookingSlot;
use App\Models\GuestCategory;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Pricing\ChargeableStay;
use App\Services\Pricing\ChargeCalculator;
use App\Services\Pricing\Occupancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What a traveller can book at a property, regardless of whether the unit
 * sells by the night (accommodation, vehicle) or by the departure (activity).
 *
 * The rule from BOOKING_BEYOND_ROOMS.md §7.1 is enforced here: no `if (activity)`
 * branch. A unit with departures is sold per departure; without, per date row.
 * That is the unit's own data, not the listing's vertical.
 *
 * @see RoomOffers for the accommodation-only predecessor this supersedes.
 */
class UnitAvailability
{
    public function __construct(
        private readonly AvailabilityCalendar $calendar,
        private readonly ChargeCalculator $charges = new ChargeCalculator,
    ) {}

    /**
     * Every active unit that has something to offer for the given parameters.
     *
     * Date-range units: checked against [checkIn, checkOut).
     * Slot-based units: checked against checkIn only, filtered by timeFrom.
     *
     * @return array<int, UnitOffer>
     */
    public function for(
        Listing $listing,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $adults = 1,
        int $children = 0,
        ?string $timeFrom = null,
    ): array {
        $plan = RatePlan::defaultFor($listing);
        $occupancy = $this->occupancyFor($listing, $plan, $adults, $children);
        $offers = [];

        /** @var Collection<int, BookableUnit> $units */
        $units = BookableUnit::query()
            ->where('listing_id', $listing->id)
            ->where('is_active', true)
            ->with(['slots' => fn ($q) => $q->where('is_active', true)->orderBy('starts_at')->orderBy('id')])
            ->orderBy('base_rate')
            ->get();

        foreach ($units as $unit) {
            $offer = $unit->slots->isNotEmpty()
                ? $this->slotBasedOffer($unit, $checkIn, $plan, $timeFrom)
                : $this->dateRangeOffer($listing, $unit, $checkIn, $checkOut, $adults, $children, $plan, $occupancy);

            if ($offer !== null) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    private function slotBasedOffer(
        BookableUnit $unit,
        CarbonInterface $date,
        ?RatePlan $plan,
        ?string $timeFrom,
    ): ?UnitOffer {
        $slots = $unit->slots->when(
            $timeFrom !== null,
            fn (Collection $c) => $c->filter(
                fn (BookingSlot $s) => strcmp($s->starts_at, $timeFrom.':00') >= 0,
            ),
        );

        $slotOffers = [];

        foreach ($slots as $slot) {
            $seats = $this->calendar->seatsFree($unit, $date, $slot);

            if ($seats < 1) {
                continue;
            }

            $rate = $this->calendar->rateForSlot($unit, $date, $slot, $plan);

            if ($rate <= 0.0) {
                continue;
            }

            $slotOffers[] = new SlotOffer(
                startsAt: $slot->timeLabel(),
                label: $slot->label(),
                durationMinutes: $slot->duration_minutes,
                unitsLeft: $seats,
                total: round($rate, 2),
                totalPayable: round($rate, 2),
            );
        }

        if ($slotOffers === []) {
            return null;
        }

        return new UnitOffer(
            bookableUnit: $unit,
            unitsLeft: null,
            periods: 1,
            currency: $unit->currency,
            total: null,
            totalPayable: null,
            charges: [],
            slots: $slotOffers,
        );
    }

    private function dateRangeOffer(
        Listing $listing,
        BookableUnit $unit,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $adults,
        int $children,
        ?RatePlan $plan,
        ?Occupancy $occupancy,
    ): ?UnitOffer {
        $unitsLeft = RoomAvailability::unitsLeft($listing->id, $unit, $checkIn, $checkOut);

        if ($unitsLeft < 1 || ! RoomCapacity::fits($unit, $adults, $children)) {
            return null;
        }

        try {
            $quote = $this->calendar->quote($unit, $checkIn, $checkOut, 1, $plan, $occupancy);
        } catch (UnpriceableStayException) {
            return null;
        }

        $total = round($quote->total(), 2);

        if ($total <= 0.0) {
            return null;
        }

        $periods = $quote->nightCount();

        $charges = $this->charges->for($listing, $checkIn, new ChargeableStay(
            stayAmount: $total,
            nights: $periods,
            adults: $adults,
            children: $children,
            unitNights: $periods,
            currency: $quote->currency,
        ));

        return new UnitOffer(
            bookableUnit: $unit,
            unitsLeft: $unitsLeft,
            periods: $periods,
            currency: $quote->currency,
            total: $total,
            totalPayable: round($total + $this->charges->addedTotal($charges), 2),
            charges: $charges,
            slots: [],
        );
    }

    private function occupancyFor(Listing $listing, ?RatePlan $plan, int $adults, int $children): ?Occupancy
    {
        if ($plan === null || ! $plan->pricing_strategy->needsOccupancy()) {
            return null;
        }

        $categories = GuestCategory::forListing($listing);
        $adultCategory = $categories->firstWhere('kind', GuestKind::Adult);
        $childCategory = $categories->firstWhere('kind', GuestKind::Child);

        $counts = [];

        if ($adultCategory !== null && $adults > 0) {
            $counts[$adultCategory->id] = $adults;
        }

        if ($childCategory !== null && $children > 0) {
            $counts[$childCategory->id] = $children;
        }

        return $counts === [] ? null : Occupancy::of($counts);
    }
}
