<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Enums\PricingStrategy;
use App\Enums\ReservationSource;
use App\Exceptions\Inventory\DepartureInUseException;
use App\Filament\Partner\Pages\OccupancyCalendar;
use App\Models\BookableUnit;
use App\Models\BookableUnitCalendarDay;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\RatePlan;
use App\Models\RatePlanDay;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriteGuard;
use App\Services\Inventory\InventoryWriter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Selling a seat from the desk: the day view opens the form on a departure,
 * and the seat comes off that departure.
 *
 * The failure this guards against is not a crash. A booking form that sells
 * nights, handed a unit that runs departures, will happily take the seat off
 * the property's own counter — a different pool — and the calendar will go on
 * offering a tour that is full. So the departure is required where a unit has
 * one, and the seat is checked against the departure's own counter.
 */
class SellingADepartureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Listing $listing;

    private BookableUnit $tour;

    private BookableUnit $chalet;

    private BookingSlot $morning;

    private BookingSlot $afternoon;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));

        $partner = Partner::create(['name' => 'Okonjima Adventures', 'email' => 'desk@example.com']);
        $this->user = User::factory()->create(['partner_id' => $partner->id]);
        $this->listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => 'Okonjima Adventures',
        ]);

        RatePlan::create([
            'listing_id' => $this->listing->id,
            'name' => 'Standard rate',
            'code' => 'STD',
            'pricing_strategy' => PricingStrategy::PerUnit,
            'is_default' => true,
        ]);

        $this->chalet = $this->unit('Bush Chalet', 'CHA', 4, 2450);
        $this->tour = $this->unit('Quad tour', 'QUAD', 8, 950);
        $this->morning = $this->slot($this->tour, '09:00', 'Morning ride');
        $this->afternoon = $this->slot($this->tour, '14:00', 'Afternoon ride');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_click_on_a_departure_opens_the_form_on_that_departure_and_that_day(): void
    {
        $page = $this->page()->call('startDeparture', $this->tour->id, '2026-09-12', $this->morning->id);

        $page->assertSet('prefillBookableUnitId', $this->tour->id)
            ->assertSet('prefillSlotId', $this->morning->id)
            ->assertSet('prefillDate', '2026-09-12');
    }

    /**
     * The same rule the cell click already follows: an id from a browser is
     * resolved against this property before anything is prefilled.
     */
    public function test_a_departure_belonging_to_another_unit_prefills_nothing(): void
    {
        $elsewhere = Listing::factory()->create();
        $theirTour = BookableUnit::factory()->create(['listing_id' => $elsewhere->id, 'total_units' => 4]);
        $theirSlot = $this->slot($theirTour, '09:00', 'Theirs');

        $this->page()
            ->call('startDeparture', $this->tour->id, '2026-09-12', $theirSlot->id)
            ->assertSet('prefillSlotId', null);

        // And a departure of *this* property, but of a different unit of it.
        $this->page()
            ->call('startDeparture', $this->chalet->id, '2026-09-12', $this->morning->id)
            ->assertSet('prefillSlotId', null);
    }

    public function test_a_seat_sold_from_the_desk_comes_off_the_departure_it_was_sold_on(): void
    {
        $this->submitBooking([
            'check_in' => '2026-09-12',
            'check_out' => '2026-09-13',
            'guest_name' => 'Anna Shipanga',
            'source' => ReservationSource::WalkIn->value,
            'adults' => 2,
            'children' => 0,
            'rooms' => [[
                'bookable_unit_id' => $this->tour->id,
                'slot_id' => $this->morning->id,
                'quantity' => 3,
            ]],
        ]);

        $calendar = app(AvailabilityCalendar::class);
        $date = Carbon::parse('2026-09-12');

        $this->assertSame(5, $calendar->seatsFree($this->tour, $date, $this->morning));
        $this->assertSame(8, $calendar->seatsFree($this->tour, $date, $this->afternoon));

        // And nothing came off the unit's own counter, which is what a night
        // would have used.
        $this->assertSame(8, $calendar->unitsFree($this->tour, $date));

        $unit = Reservation::query()->latest('id')->firstOrFail()->units->first();

        $this->assertSame($this->morning->id, $unit?->slot_id);
        $this->assertSame('Morning ride', $unit?->slot_label);
    }

    /**
     * Two rows of the same unit are merged into one line — two rooms of the
     * same type on one booking are two rooms, not two bookings. Two rows on
     * two *departures* must not be, because they are two pools of seats and a
     * merged line would hold four on one tour.
     */
    public function test_the_same_unit_on_two_departures_is_two_lines(): void
    {
        $this->submitBooking([
            'check_in' => '2026-09-12',
            'check_out' => '2026-09-13',
            'guest_name' => 'Anna Shipanga',
            'source' => ReservationSource::WalkIn->value,
            'adults' => 2,
            'children' => 0,
            'rooms' => [
                ['bookable_unit_id' => $this->tour->id, 'slot_id' => $this->morning->id, 'quantity' => 2],
                ['bookable_unit_id' => $this->tour->id, 'slot_id' => $this->afternoon->id, 'quantity' => 2],
            ],
        ]);

        $calendar = app(AvailabilityCalendar::class);
        $date = Carbon::parse('2026-09-12');

        $this->assertSame(6, $calendar->seatsFree($this->tour, $date, $this->morning));
        $this->assertSame(6, $calendar->seatsFree($this->tour, $date, $this->afternoon));
        $this->assertCount(2, Reservation::query()->latest('id')->firstOrFail()->units);
    }

    public function test_a_unit_that_runs_departures_will_not_sell_without_one(): void
    {
        $this->submitBooking([
            'check_in' => '2026-09-12',
            'check_out' => '2026-09-13',
            'guest_name' => 'Anna Shipanga',
            'source' => ReservationSource::WalkIn->value,
            'adults' => 2,
            'children' => 0,
            'rooms' => [[
                'bookable_unit_id' => $this->tour->id,
                'quantity' => 1,
            ]],
        ]);

        $this->assertSame(0, Reservation::query()->count());
    }

    /** A lodge is asked nothing: the field is not on its form at all. */
    public function test_a_room_sold_by_the_night_is_never_asked_for_a_departure(): void
    {
        $this->submitBooking([
            'check_in' => '2026-09-12',
            'check_out' => '2026-09-14',
            'guest_name' => 'Familie Braun',
            'source' => ReservationSource::WalkIn->value,
            'adults' => 2,
            'children' => 0,
            'rooms' => [[
                'bookable_unit_id' => $this->chalet->id,
                'quantity' => 1,
            ]],
        ]);

        $unit = Reservation::query()->latest('id')->firstOrFail()->units->first();

        $this->assertNull($unit?->slot_id);
        $this->assertSame(3, app(AvailabilityCalendar::class)->unitsFree($this->chalet, Carbon::parse('2026-09-12')));
    }

    public function test_the_last_seat_is_refused_with_the_departure_named(): void
    {
        $this->sell($this->morning, seats: 8, date: '2026-09-12');

        $this->submitBooking([
            'check_in' => '2026-09-12',
            'check_out' => '2026-09-13',
            'guest_name' => 'One Too Many',
            'source' => ReservationSource::WalkIn->value,
            'adults' => 1,
            'children' => 0,
            'rooms' => [[
                'bookable_unit_id' => $this->tour->id,
                'slot_id' => $this->morning->id,
                'quantity' => 1,
            ]],
        ]);

        // Still one stay: the full one.
        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(8, app(AvailabilityCalendar::class)->seatsFree($this->tour, Carbon::parse('2026-09-12'), $this->afternoon));
    }

    /**
     * A departure's price is its own, and that is what the desk charges.
     */
    public function test_a_seat_is_charged_what_its_own_departure_costs(): void
    {
        $plan = RatePlan::defaultFor($this->listing);

        InventoryWriteGuard::allow(fn () => RatePlanDay::create([
            'rate_plan_id' => $plan?->id,
            'bookable_unit_id' => $this->tour->id,
            'slot_id' => $this->afternoon->id,
            'date' => '2026-09-12',
            'rate' => 1400,
        ]));

        $this->sell($this->afternoon, seats: 2, date: '2026-09-12');

        $this->assertSame(2800.0, (float) Reservation::query()->latest('id')->firstOrFail()->total_amount);
    }

    /**
     * A stay restriction is a rule about selling a night. A lodge's three-night
     * minimum has no business refusing a morning ride.
     */
    public function test_a_nights_restriction_does_not_reach_a_departure(): void
    {
        app(InventoryWriter::class)->setCalendar(
            $this->tour,
            Carbon::parse('2026-09-12'),
            Carbon::parse('2026-09-12'),
            ['min_stay' => 3, 'closed_to_arrival' => true],
        );

        $this->submitBooking([
            'check_in' => '2026-09-12',
            'check_out' => '2026-09-13',
            'guest_name' => 'Anna Shipanga',
            'source' => ReservationSource::WalkIn->value,
            'adults' => 1,
            'children' => 0,
            'rooms' => [[
                'bookable_unit_id' => $this->tour->id,
                'slot_id' => $this->morning->id,
                'quantity' => 1,
            ]],
        ]);

        $this->assertSame(7, app(AvailabilityCalendar::class)->seatsFree($this->tour, Carbon::parse('2026-09-12'), $this->morning));
    }

    /**
     * The hazard the model guard exists for: both foreign keys cascade, so a
     * delete reaches the counters through the database — below Eloquent and
     * past the inventory write guard.
     */
    public function test_a_departure_with_seats_on_it_cannot_be_deleted(): void
    {
        $this->sell($this->morning, seats: 2, date: '2026-09-12');

        try {
            $this->morning->delete();
            $this->fail('Deleting a departure that has been sold must be refused.');
        } catch (DepartureInUseException $refusal) {
            $this->assertStringContainsString('Morning ride', $refusal->getMessage());
        }

        $this->assertTrue($this->morning->exists());
        $this->assertSame(
            1,
            BookableUnitCalendarDay::query()->where('slot_id', $this->morning->id)->where('units_sold', '>', 0)->count(),
        );

        // An empty one is ordinary housekeeping and goes.
        $this->afternoon->delete();

        $this->assertSame(0, BookingSlot::query()->where('id', $this->afternoon->id)->count());
    }

    private function page(): Testable
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));

        return Livewire::test(OccupancyCalendar::class);
    }

    /**
     * Open the booking form and submit it.
     *
     * Not Filament's callAction() helper, for the reason LodgeOccupancyBookingTest
     * writes out: it dot-flattens the data, so `rooms` lands *beside* the row
     * the form was prefilled with rather than replacing it, and a booking for
     * one seat quietly becomes two lines. Setting each top-level key whole
     * avoids the question.
     *
     * @param  array<string, mixed>  $data
     */
    private function submitBooking(array $data): void
    {
        $page = $this->page()->mountAction('createBooking');

        foreach ($data as $key => $value) {
            $page->set("mountedActionsData.0.{$key}", $value);
        }

        $page->callMountedAction();
    }

    private function sell(BookingSlot $slot, int $seats, string $date): void
    {
        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $this->listing,
            lines: [new BookingLine($this->tour, $seats, Carbon::parse($date), Carbon::parse($date)->addDay(), slot: $slot)],
            guestName: 'Seat Guest',
            source: ReservationSource::WalkIn,
            notify: false,
        ));
    }

    private function unit(string $name, string $code, int $units, float $rate): BookableUnit
    {
        return BookableUnit::factory()->create([
            'listing_id' => $this->listing->id,
            'name' => $name,
            'code' => $code,
            'total_units' => $units,
            'base_rate' => $rate,
            'currency' => 'NAD',
        ]);
    }

    private function slot(BookableUnit $unit, string $time, string $label): BookingSlot
    {
        return BookingSlot::create([
            'bookable_unit_id' => $unit->id,
            'label' => $label,
            'starts_at' => $time,
            'duration_minutes' => 180,
        ]);
    }
}
