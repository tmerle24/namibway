<?php

namespace Tests\Feature;

use App\Enums\ChargeBasis;
use App\Enums\ChargeKind;
use App\Enums\DiscountType;
use App\Enums\PricingStrategy;
use App\Enums\ReservationSource;
use App\Models\Charge;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Promotion;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The trip plan and the lodge now read one calendar.
 *
 * Before this, the picker priced a room as its base rate times the nights and
 * counted availability from overlapping requests alone. Both were wrong in the
 * same way: they could not see what the property had actually done in its own
 * booking system. A lodge could fill itself up at the desk while the trip plan
 * kept offering the same rooms, and a season the lodge had priced never
 * reached the traveler.
 */
class RoomOffersTest extends TestCase
{
    use RefreshDatabase;

    private Listing $listing;

    private RoomType $room;

    private RatePlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 09:00:00'));

        $partner = Partner::create(['name' => 'Okonjima', 'email' => 'stay@okonjima.test']);
        $this->listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'is_published' => true,
            'accepts_inquiries' => true,
        ]);
        $this->room = RoomType::create([
            'listing_id' => $this->listing->id,
            'code' => 'standard',
            'name' => 'Standard Double',
            'max_adults' => 2,
            'max_children' => 0,
            'total_units' => 2,
            'rate_per_night' => 1200,
            'currency' => 'NAD',
            'is_active' => true,
        ]);
        $this->plan = RatePlan::create([
            'listing_id' => $this->listing->id,
            'name' => 'Standard rate',
            'code' => 'STD',
            'pricing_strategy' => PricingStrategy::PerUnit,
            'is_default' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function url(string $checkIn = '2026-09-01', string $checkOut = '2026-09-04'): string
    {
        return route('listings.room-types', $this->listing->slug)
            ."?check_in={$checkIn}&check_out={$checkOut}&adults=2&children=0";
    }

    public function test_a_property_with_no_calendar_reads_exactly_as_it_did_before(): void
    {
        // The calendar is sparse: a room type with no rows falls back to its
        // own rate and unit count. This is most listings today, and switching
        // readers must not move a single number for them.
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('rooms.0.price_per_night', 1200)
            ->assertJsonPath('rooms.0.total_price', 3600)
            ->assertJsonPath('rooms.0.total_payable', 3600)
            ->assertJsonPath('rooms.0.units_left', 2);
    }

    public function test_the_season_the_lodge_priced_reaches_the_traveler(): void
    {
        app(InventoryWriter::class)->setRates(
            $this->plan,
            $this->room,
            Carbon::parse('2026-09-02'),
            Carbon::parse('2026-09-03'),
            ['rate' => 2400],
        );

        // Two nights at 2,400 and one at the room's own 1,200.
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('rooms.0.total_price', 6000)
            ->assertJsonPath('rooms.0.nightly_rates', [1200, 2400, 2400])
            // The headline figure is the average, and the nights are there to
            // check it against.
            ->assertJsonPath('rooms.0.price_per_night', 2000);
    }

    public function test_a_booking_the_lodge_took_at_its_own_desk_stops_being_offered_online(): void
    {
        $this->book('2026-09-01', '2026-09-04');

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('rooms.0.units_left', 1);

        $this->book('2026-09-01', '2026-09-04');

        // Sold out on the calendar, so it is not offered at all — before this,
        // the trip plan would have gone on selling both rooms.
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(0, 'rooms');
    }

    public function test_a_room_the_lodge_took_off_sale_is_not_offered(): void
    {
        $this->book('2026-09-02', '2026-09-03');

        // The stay is one night inside the range, and unitsFreeThroughout is
        // the minimum across the nights: one night short is short.
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('rooms.0.units_left', 1);
    }

    public function test_tax_the_property_adds_is_named_and_in_the_total(): void
    {
        Charge::create([
            'listing_id' => $this->listing->id,
            'name' => 'VAT',
            'kind' => ChargeKind::Tax,
            'basis' => ChargeBasis::Percentage,
            'rate' => 15,
        ]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('rooms.0.total_price', 3600)
            ->assertJsonPath('rooms.0.total_payable', 4140)
            ->assertJsonPath('rooms.0.charges.0.name', 'VAT')
            ->assertJsonPath('rooms.0.charges.0.amount', 540)
            ->assertJsonPath('rooms.0.charges.0.included', false);
    }

    public function test_a_tax_already_in_the_rate_is_named_without_moving_the_total(): void
    {
        Charge::create([
            'listing_id' => $this->listing->id,
            'name' => 'VAT',
            'kind' => ChargeKind::Tax,
            'basis' => ChargeBasis::Percentage,
            'rate' => 15,
            'is_included' => true,
        ]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('rooms.0.total_payable', 3600)
            ->assertJsonPath('rooms.0.charges.0.included', true);
    }

    /**
     * Nobody having priced a room reads as zero rather than as a refusal: the
     * calendar is sparse, so "no rate anywhere" falls back to the room type's
     * own rate, which is what a lodge leaves at zero until it gets round to it.
     */
    public function test_a_room_nobody_priced_is_left_out_rather_than_offered_at_nothing(): void
    {
        RoomType::create([
            'listing_id' => $this->listing->id,
            'code' => 'unpriced',
            'name' => 'Riverside Chalet',
            'max_adults' => 2,
            'max_children' => 0,
            'total_units' => 1,
            'rate_per_night' => 0,
            'currency' => 'NAD',
            'is_active' => true,
        ]);

        $response = $this->getJson($this->url())->assertOk();

        $this->assertSame(['standard'], collect($response->json('rooms'))->pluck('code')->all());
    }

    /**
     * An offer belongs to the lodge's pricing, not to the picker: it applies at
     * booking. The picker shows the calendar's price, which is what the stay
     * costs before anything is taken off — quoting the discount here and then
     * applying it again at booking would take it twice.
     */
    public function test_an_offer_does_not_reach_into_the_quoted_room_price(): void
    {
        Promotion::create([
            'listing_id' => $this->listing->id,
            'name' => 'Green season',
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 20,
            'is_active' => true,
        ]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('rooms.0.total_price', 3600);
    }

    private function book(string $checkIn, string $checkOut): void
    {
        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $this->listing,
            lines: [new BookingLine($this->room, 1, Carbon::parse($checkIn), Carbon::parse($checkOut), $this->plan)],
            guestName: 'Desk Guest',
            source: ReservationSource::WalkIn,
            notify: false,
        ));
    }
}
