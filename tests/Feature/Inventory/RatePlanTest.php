<?php

namespace Tests\Feature\Inventory;

use App\Enums\ReservationSource;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\RatePlanDay;
use App\Models\RoomType;
use App\Models\RoomTypeCalendarDay;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Inventory\OccupancyGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The split the whole design rests on: **a room is sold once however many rate
 * plans it is offered under.**
 *
 * Inventory stays keyed by room type; rates and restrictions are keyed by rate
 * plan as well. Getting it wrong in either direction is expensive — counters
 * per plan would oversell the property, a shared rate would make plans
 * pointless — so both halves are asserted here rather than assumed.
 */
class RatePlanTest extends TestCase
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

    private function listing(): Listing
    {
        return Listing::factory()->create();
    }

    /** @param array<string, mixed> $attributes */
    private function room(Listing $listing, array $attributes = []): RoomType
    {
        return RoomType::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 3,
            'rate_per_night' => 1000,
            'currency' => 'NAD',
            ...$attributes,
        ]);
    }

    public function test_a_property_with_no_rate_plan_charges_what_the_room_type_charges(): void
    {
        $room = $this->room($this->listing());

        $this->assertNull(RatePlan::defaultFor($room->listing));
        $this->assertSame(1000.0, $this->calendar->rateFor($room, Carbon::parse('2026-09-10')));
    }

    public function test_two_rate_plans_price_the_same_night_differently(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $resident = RatePlan::create([
            'listing_id' => $listing->id, 'name' => 'Namibian resident', 'code' => 'RES', 'is_default' => true,
        ]);
        $international = RatePlan::create([
            'listing_id' => $listing->id, 'name' => 'International', 'code' => 'INT',
        ]);

        $night = Carbon::parse('2026-09-10');

        $this->writer->setRates($resident, $room, $night, $night, ['rate' => 900]);
        $this->writer->setRates($international, $room, $night, $night, ['rate' => 2400]);

        $this->assertSame(900.0, $this->calendar->rateFor($room, $night, $resident));
        $this->assertSame(2400.0, $this->calendar->rateFor($room, $night, $international));

        // And a plan that has said nothing still falls back to the room type.
        $quiet = RatePlan::create(['listing_id' => $listing->id, 'name' => 'Agent', 'code' => 'AGT']);
        $this->assertSame(1000.0, $this->calendar->rateFor($room, $night, $quiet));
    }

    public function test_selling_under_one_plan_takes_the_room_from_all_of_them(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['total_units' => 1]);

        $resident = RatePlan::create([
            'listing_id' => $listing->id, 'name' => 'Namibian resident', 'code' => 'RES', 'is_default' => true,
        ]);
        $international = RatePlan::create([
            'listing_id' => $listing->id, 'name' => 'International', 'code' => 'INT',
        ]);

        $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'), $resident)],
            guestName: 'Resident Guest',
            source: ReservationSource::WalkIn,
        ));

        // The room is gone, full stop. Availability is not a property of a
        // product — this is the assertion the whole split exists for.
        $this->assertSame(0, $this->calendar->unitsFree($room, Carbon::parse('2026-09-10')));

        $this->expectException(\App\Exceptions\Inventory\InventoryUnavailableException::class);

        $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'), $international)],
            guestName: 'International Guest',
            source: ReservationSource::Website,
        ));
    }

    public function test_a_stay_records_which_plan_it_was_sold_under(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);
        $plan = RatePlan::create([
            'listing_id' => $listing->id, 'name' => 'Non-refundable', 'code' => 'NR', 'is_refundable' => false,
        ]);

        $reservation = $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-12'), $plan)],
            guestName: 'Bargain Hunter',
            source: ReservationSource::Website,
        ));

        $this->assertSame($plan->id, $reservation->units->first()->rate_plan_id);
    }

    public function test_a_booking_that_names_no_plan_falls_back_to_the_default(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);
        $default = RatePlan::ensureDefaultFor($listing);

        $reservation = $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'))],
            guestName: 'Walk In',
            source: ReservationSource::WalkIn,
        ));

        $this->assertSame($default->id, $reservation->units->first()->rate_plan_id);
    }

    public function test_restrictions_belong_to_the_plan_that_set_them(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $strict = RatePlan::create(['listing_id' => $listing->id, 'name' => 'Strict', 'code' => 'STR', 'is_default' => true]);
        $flexible = RatePlan::create(['listing_id' => $listing->id, 'name' => 'Flexible', 'code' => 'FLX']);

        $friday = Carbon::parse('2026-09-11');
        $this->writer->setRates($strict, $room, $friday, $friday, ['min_stay' => 3]);

        $this->assertFalse($this->calendar->passesStayRules($room, $friday, $friday->copy()->addDay(), $strict));
        $this->assertTrue($this->calendar->passesStayRules($room, $friday, $friday->copy()->addDay(), $flexible));
    }

    public function test_capacity_is_written_to_the_inventory_calendar_not_a_rate_plan(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);
        $plan = RatePlan::ensureDefaultFor($listing);

        $from = Carbon::parse('2026-09-10');
        $to = Carbon::parse('2026-09-12');

        $this->writer->setInventory($room, $from, $to, ['units_total' => 1]);
        $this->writer->setRates($plan, $room, $from, $to, ['rate' => 1500]);

        $this->assertSame(1, $this->calendar->capacityFor($room, $from));
        $this->assertSame(1500.0, $this->calendar->rateFor($room, $from, $plan));

        // Each landed in its own table, and neither reached into the other.
        $this->assertSame(3, RoomTypeCalendarDay::where('room_type_id', $room->id)->count());
        $this->assertSame(3, RatePlanDay::where('rate_plan_id', $plan->id)->count());
    }

    public function test_neither_writer_can_be_aimed_at_the_other_half(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);
        $plan = RatePlan::ensureDefaultFor($listing);
        $day = Carbon::parse('2026-09-10');

        try {
            $this->writer->setRates($plan, $room, $day, $day, ['units_total' => 1]);
            $this->fail('setRates() should not write inventory.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->expectException(InvalidArgumentException::class);
        $this->writer->setInventory($room, $day, $day, ['rate' => 1]);
    }

    public function test_a_rate_plan_cannot_price_another_propertys_room(): void
    {
        $plan = RatePlan::ensureDefaultFor($this->listing());
        $foreign = $this->room($this->listing());
        $day = Carbon::parse('2026-09-10');

        $this->expectException(InvalidArgumentException::class);
        $this->writer->setRates($plan, $foreign, $day, $day, ['rate' => 1]);
    }

    public function test_the_calendar_grid_shows_the_plan_it_was_asked_for(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['name' => 'Standard']);

        $resident = RatePlan::create([
            'listing_id' => $listing->id, 'name' => 'Resident', 'code' => 'RES', 'is_default' => true,
        ]);
        $international = RatePlan::create([
            'listing_id' => $listing->id, 'name' => 'International', 'code' => 'INT',
        ]);

        $from = Carbon::parse('2026-09-10');
        $this->writer->setRates($resident, $room, $from, $from->copy()->addDays(5), ['rate' => 900]);
        $this->writer->setRates($international, $room, $from, $from->copy()->addDays(5), ['rate' => 2400]);

        $grid = app(OccupancyGrid::class);

        // No plan named: the default, which is what every existing screen gets.
        $this->assertSame(900.0, $grid->build($listing, $from, 3)->rows[0]->cells[0]->rate);
        $this->assertSame(2400.0, $grid->build($listing, $from, 3, $international)->rows[0]->cells[0]->rate);
    }

    public function test_a_property_gets_at_most_one_default_plan(): void
    {
        $listing = $this->listing();

        $first = RatePlan::ensureDefaultFor($listing);
        $second = RatePlan::ensureDefaultFor($listing);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RatePlan::where('listing_id', $listing->id)->count());
    }
}
