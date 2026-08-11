<?php

namespace Tests\Feature\Inventory;

use App\Enums\GuestKind;
use App\Enums\PricingStrategy;
use App\Enums\ReservationSource;
use App\Exceptions\Pricing\UnpriceableStayException;
use App\Models\GuestCategory;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\RatePlanGuestAmount;
use App\Models\ReservationGuest;
use App\Models\RoomType;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Pricing\GuestCategoryDefaults;
use App\Services\Pricing\Occupancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Pricing by who is in the room, end to end: the amounts a lodge enters, the
 * price a stay is written at, and the record of the occupancy it came from.
 *
 * The arithmetic itself is checked in NightPricerTest against a table. What is
 * checked here is the wiring — that the numbers reach the calculation, that a
 * season still works, and that a stay carries its guests afterwards, which is
 * the difference between a price and a price somebody can account for.
 */
class OccupancyPricingTest extends TestCase
{
    use RefreshDatabase;

    private InventoryWriter $writer;

    private AvailabilityCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(InventoryWriter::class);
        $this->calendar = app(AvailabilityCalendar::class);
    }

    private function room(Listing $listing): RoomType
    {
        return RoomType::factory()->create([
            'listing_id' => $listing->id,
            'name' => 'Standard Chalet',
            'max_adults' => 4,
            'max_children' => 2,
            'total_units' => 3,
            'rate_per_night' => 1200,
            'currency' => 'NAD',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function plan(Listing $listing, PricingStrategy $strategy, array $attributes = []): RatePlan
    {
        return RatePlan::create([
            'listing_id' => $listing->id,
            'name' => 'Standard rate',
            'code' => 'STD',
            'pricing_strategy' => $strategy,
            'is_default' => true,
            ...$attributes,
        ]);
    }

    /** @return array<string, GuestCategory> */
    private function categories(Listing $listing): array
    {
        $keyed = [];

        foreach (GuestCategoryDefaults::ensureFor($listing) as $category) {
            $keyed[$category->code] = $category;
        }

        return $keyed;
    }

    public function test_the_usual_guest_types_are_created_once_and_never_overwritten(): void
    {
        $listing = Listing::factory()->create();

        $first = $this->categories($listing);
        $this->assertSame(['AD', 'CH', 'IN'], array_keys($first));
        $this->assertSame(50.0, $first['CH']->charge_percent);
        $this->assertFalse($first['IN']->counts_toward_occupancy);

        // A lodge's own band is not ours to correct on a second run.
        $first['CH']->update(['min_age' => 2, 'max_age' => 15, 'charge_percent' => 60]);

        $again = $this->categories($listing);
        $this->assertCount(3, $again);
        $this->assertSame(60.0, $again['CH']->charge_percent);
        $this->assertSame(15, $again['CH']->max_age);
    }

    public function test_a_stay_is_priced_by_the_number_of_guests_and_records_who_they_were(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room($listing);
        $plan = $this->plan($listing, PricingStrategy::PerOccupancy);
        $categories = $this->categories($listing);

        $from = Carbon::parse('2026-09-10');
        $to = Carbon::parse('2026-09-11');

        $this->writer->setGuestAmounts($plan, $room, $from, $to, [1 => 1000, 2 => 1300, 3 => 1500]);

        $reservation = $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine(
                $room,
                1,
                $from,
                $to->copy()->addDay(),
                $plan,
                Occupancy::of([$categories['AD']->id => 2, $categories['CH']->id => 1]),
            )],
            guestName: 'Family Of Three',
            source: ReservationSource::WalkIn,
        ));

        // Three occupants, two nights, at the three-guest price.
        $this->assertSame(3000.0, $reservation->total_amount);

        $unit = $reservation->units->first();
        $guests = ReservationGuest::where('reservation_unit_id', $unit->id)->pluck('count', 'guest_category_id');

        $this->assertSame(2, $guests[$categories['AD']->id]);
        $this->assertSame(1, $guests[$categories['CH']->id]);
    }

    public function test_the_guest_amounts_follow_the_season(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room($listing);
        $plan = $this->plan($listing, PricingStrategy::PerOccupancy);
        $categories = $this->categories($listing);

        $this->writer->setGuestAmounts($plan, $room, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), [2 => 1300]);
        $this->writer->setGuestAmounts($plan, $room, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), [2 => 1800]);

        $two = Occupancy::of([$categories['AD']->id => 2]);

        // A stay across the boundary prices per night, with no special case —
        // the same property the whole calendar rests on.
        $quote = $this->calendar->quote(
            $room,
            Carbon::parse('2026-06-30'),
            Carbon::parse('2026-07-02'),
            1,
            $plan,
            $two,
        );

        $this->assertSame(3100.0, $quote->total());
    }

    public function test_removing_a_guest_count_takes_it_off_sale(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room($listing);
        $plan = $this->plan($listing, PricingStrategy::PerOccupancy);
        $categories = $this->categories($listing);

        $night = Carbon::parse('2026-09-10');
        $this->writer->setGuestAmounts($plan, $room, $night, $night, [2 => 1300, 4 => 2000]);

        $this->writer->setGuestAmounts($plan, $room, $night, $night, [4 => null]);

        $this->assertSame(1, RatePlanGuestAmount::where('rate_plan_id', $plan->id)->count());

        $this->expectException(UnpriceableStayException::class);

        $this->calendar->quote(
            $room,
            $night,
            $night->copy()->addDay(),
            1,
            $plan,
            Occupancy::of([$categories['AD']->id => 4]),
        );
    }

    public function test_per_person_sharing_prices_a_family_from_one_number(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room($listing);
        $plan = $this->plan($listing, PricingStrategy::PerPersonSharing, [
            'single_supplement_amount' => 500,
        ]);
        $categories = $this->categories($listing);

        $night = Carbon::parse('2026-09-10');
        $this->writer->setRates($plan, $room, $night, $night, ['rate' => 1500]);

        $sharing = $this->calendar->quote($room, $night, $night->copy()->addDay(), 1, $plan, Occupancy::of([
            $categories['AD']->id => 2,
            $categories['CH']->id => 1,
        ]));

        $alone = $this->calendar->quote($room, $night, $night->copy()->addDay(), 1, $plan, Occupancy::of([
            $categories['AD']->id => 1,
        ]));

        $this->assertSame(3750.0, $sharing->total());
        $this->assertSame(2000.0, $alone->total());
    }

    public function test_a_room_cannot_be_sold_to_more_people_than_it_sleeps(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room($listing);
        $plan = $this->plan($listing, PricingStrategy::PerPersonSharing);
        $categories = $this->categories($listing);

        $night = Carbon::parse('2026-09-10');
        $this->writer->setRates($plan, $room, $night, $night, ['rate' => 1500]);

        $this->expectException(UnpriceableStayException::class);
        $this->expectExceptionMessage('sleeps 6');

        $this->calendar->quote($room, $night, $night->copy()->addDay(), 1, $plan, Occupancy::of([
            $categories['AD']->id => 7,
        ]));
    }

    public function test_a_per_room_plan_never_asks_who_is_in_the_room(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room($listing);
        $plan = $this->plan($listing, PricingStrategy::PerUnit);

        $night = Carbon::parse('2026-09-10');
        $this->writer->setRates($plan, $room, $night, $night, ['rate' => 1800]);

        $quote = $this->calendar->quote($room, $night, $night->copy()->addDay(), 1, $plan);

        $this->assertSame(1800.0, $quote->total());
    }

    public function test_guest_amounts_cannot_be_written_for_another_propertys_room(): void
    {
        $mine = Listing::factory()->create();
        $plan = $this->plan($mine, PricingStrategy::PerOccupancy);
        $theirs = $this->room(Listing::factory()->create());
        $night = Carbon::parse('2026-09-10');

        $this->expectException(InvalidArgumentException::class);

        $this->writer->setGuestAmounts($plan, $theirs, $night, $night, [2 => 1300]);
    }

    public function test_only_one_rate_plan_can_be_the_default(): void
    {
        $listing = Listing::factory()->create();

        $first = $this->plan($listing, PricingStrategy::PerUnit);
        $second = RatePlan::create([
            'listing_id' => $listing->id,
            'name' => 'Namibian resident',
            'code' => 'RES',
            'pricing_strategy' => PricingStrategy::PerUnit,
            'is_default' => true,
        ]);

        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($second->refresh()->is_default);
        $this->assertSame($second->id, RatePlan::defaultFor($listing)?->id);
    }

    public function test_a_guest_type_in_use_cannot_simply_be_deleted(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room($listing);
        $plan = $this->plan($listing, PricingStrategy::PerPersonSharing);
        $categories = $this->categories($listing);

        $night = Carbon::parse('2026-09-10');
        $this->writer->setRates($plan, $room, $night, $night, ['rate' => 1500]);

        $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, $night, $night->copy()->addDay(), $plan, Occupancy::of([
                $categories['AD']->id => 2,
            ]))],
            guestName: 'Two Adults',
            source: ReservationSource::WalkIn,
        ));

        // A stay whose guests became "unknown" would be unreadable at the
        // desk, so the database refuses. Deactivating is the way out.
        $this->expectException(QueryException::class);

        $categories['AD']->delete();
    }

    public function test_an_adult_is_the_kind_the_single_supplement_looks_for(): void
    {
        $listing = Listing::factory()->create();
        $categories = $this->categories($listing);

        $this->assertSame(GuestKind::Adult, $categories['AD']->kind);
        $this->assertSame(GuestKind::Infant, $categories['IN']->kind);
    }
}
