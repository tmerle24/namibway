<?php

namespace App\Services\Booking;

use App\Enums\GuestKind;
use App\Exceptions\Pricing\UnpriceableStayException;
use App\Models\BookableUnit;
use App\Models\GuestCategory;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DTOs\NightlyRate;
use App\Services\Pricing\ChargeableStay;
use App\Services\Pricing\ChargeCalculator;
use App\Services\Pricing\Occupancy;
use Carbon\CarbonInterface;

/**
 * What a traveller can book at a property, and what it costs — read from the
 * same calendar the lodge sells from.
 *
 * This is the seam CLAUDE.md called "the later, deliberate step": the trip
 * plan used to price a room as `rate_per_night × nights` and count
 * availability from overlapping inquiries, while the lodge-facing system
 * priced from rate plans and counted ARI units. Two readers, two answers, and
 * the difference only becomes visible when a real property enters real rates —
 * which is to say, on the first day it matters.
 *
 * Now the calendar answers both questions, so a season, a rate plan, an offer
 * and a tax reach the traveller exactly as they reach the invoice.
 *
 * ## What is still not merged, and why it is safe
 *
 * Requests in flight are not in the calendar. An inquiry nobody has confirmed
 * holds no ARI units, so availability here is the **smaller** of the two
 * counts: what the calendar says is free, and what is left after the requests
 * already asking for the same nights. That is deliberately conservative in
 * both directions — a room the lodge sold at its own desk stops being offered
 * online, which it did not before, and a room three travellers are already
 * asking about is not offered to a fourth.
 *
 * Moving holds into the calendar as real inventory is the step that removes
 * the second count entirely. It is not this one.
 */
class RoomOffers
{
    public function __construct(
        private readonly AvailabilityCalendar $calendar,
        private readonly ChargeCalculator $charges = new ChargeCalculator,
    ) {}

    /**
     * Every active room type that is free, fits the party and has a price.
     *
     * A room nobody has priced is left out rather than offered at zero: the
     * booking would be refused at the other end, and a traveller reading "N$ 0"
     * has been told something false about a real lodge.
     *
     * @return array<int, RoomOffer>
     */
    public function for(
        Listing $listing,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $adults = 1,
        int $children = 0,
    ): array {
        $plan = RatePlan::defaultFor($listing);
        $occupancy = $this->occupancyFor($listing, $plan, $adults, $children);
        $offers = [];

        foreach (RoomAvailability::bookableFor($listing->id, $checkIn, $checkOut, $adults, $children) as $row) {
            /** @var BookableUnit $bookableUnit */
            $bookableUnit = $row['bookable_unit'];

            try {
                $quote = $this->calendar->quote($bookableUnit, $checkIn, $checkOut, 1, $plan, $occupancy);
            } catch (UnpriceableStayException) {
                continue;
            }

            $stay = round($quote->total(), 2);

            // A room nobody has priced comes back as zero rather than as a
            // refusal — the calendar is sparse, so "no rate anywhere" reads as
            // the room type's own rate, which is what a lodge leaves at zero
            // until it gets round to pricing. Offering it would put "N$ 0"
            // beside a Book button for a real property, and the booking would
            // be refused at the other end anyway. The panel's own setup notice
            // calls the same rooms unpriced, so the two screens agree.
            if ($stay <= 0.0) {
                continue;
            }
            $nights = $quote->nightCount();

            $charges = $this->charges->for($listing, $checkIn, new ChargeableStay(
                stayAmount: $stay,
                nights: $nights,
                adults: $adults,
                children: $children,
                // One room per offer — the traveller picks how many afterwards.
                unitNights: $nights,
                currency: $quote->currency,
            ));

            $offers[] = new RoomOffer(
                bookableUnit: $bookableUnit,
                unitsLeft: $row['units_left'],
                nights: $nights,
                currency: $quote->currency,
                nightlyRates: array_map(fn (NightlyRate $night) => round($night->subtotal(), 2), $quote->nights),
                stayAmount: $stay,
                charges: $charges,
                totalPayable: round($stay + $this->charges->addedTotal($charges), 2),
            );
        }

        return $offers;
    }

    /**
     * Who to price for, when the property's rate plan prices by people.
     *
     * The picker asks how many adults and children, not which age band, so the
     * two are mapped onto the property's own categories by kind. A property
     * pricing per room gets nothing, which is the right answer rather than an
     * empty one: asking would be a form field with no purpose.
     */
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

        // No categories at all means the property has chosen a per-person plan
        // and not said what a person is. The quote refuses, the room is left
        // out, and that is better than inventing a band.
        return $counts === [] ? null : Occupancy::of($counts);
    }
}
