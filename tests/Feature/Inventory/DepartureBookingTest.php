<?php

namespace Tests\Feature\Inventory;

use App\Enums\PricingStrategy;
use App\Enums\ReservationSource;
use App\Exceptions\Inventory\InventoryUnavailableException;
use App\Models\BookableUnit;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\RatePlanDay;
use App\Models\Reservation;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriteGuard;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Selling a departure rather than a night.
 *
 * The claim being tested is that a departure is an ordinary row with an
 * ordinary counter: everything that already holds for a night — capacity,
 * refusing to oversell, giving seats back on a cancellation — holds for it
 * without a second mechanism.
 */
class DepartureBookingTest extends TestCase
{
    use RefreshDatabase;

    private Listing $listing;

    private BookableUnit $unit;

    private BookingSlot $morning;

    private BookingSlot $afternoon;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 09:00:00'));

        $this->listing = Listing::factory()->create();
        $this->unit = BookableUnit::factory()->create([
            'listing_id' => $this->listing->id,
            'name' => 'Quad tour',
            'total_units' => 8,
            'base_rate' => 950,
            'currency' => 'NAD',
        ]);
        $this->morning = $this->slot('09:00', 'Morning departure');
        $this->afternoon = $this->slot('14:00', 'Afternoon departure');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_seat_comes_off_the_departure_it_was_sold_on(): void
    {
        $this->book($this->morning, seats: 3);

        $calendar = app(AvailabilityCalendar::class);

        $this->assertSame(5, $calendar->seatsFree($this->unit, Carbon::parse('2026-09-10'), $this->morning));

        // The other departure on the same day is untouched, which is the whole
        // reason a slot is a row rather than a flag.
        $this->assertSame(8, $calendar->seatsFree($this->unit, Carbon::parse('2026-09-10'), $this->afternoon));
    }

    public function test_a_full_departure_refuses_the_ninth_seat(): void
    {
        $this->book($this->morning, seats: 8);

        $this->expectException(InventoryUnavailableException::class);

        $this->book($this->morning, seats: 1);
    }

    public function test_a_full_departure_does_not_close_the_next_one(): void
    {
        $this->book($this->morning, seats: 8);

        $afternoon = $this->book($this->afternoon, seats: 2);

        $this->assertNotNull($afternoon->id);
        $this->assertSame(6, app(AvailabilityCalendar::class)->seatsFree($this->unit, Carbon::parse('2026-09-10'), $this->afternoon));
    }

    public function test_cancelling_gives_the_seats_back_to_the_right_departure(): void
    {
        $morning = $this->book($this->morning, seats: 4);
        $this->book($this->afternoon, seats: 4);

        app(InventoryWriter::class)->cancel($morning, 'Guest called');

        $calendar = app(AvailabilityCalendar::class);

        $this->assertSame(8, $calendar->seatsFree($this->unit, Carbon::parse('2026-09-10'), $this->morning));
        $this->assertSame(4, $calendar->seatsFree($this->unit, Carbon::parse('2026-09-10'), $this->afternoon));
    }

    public function test_the_departure_is_frozen_onto_the_stay(): void
    {
        $stay = $this->book($this->morning, seats: 2);
        $unit = $stay->units->first();

        $this->assertSame($this->morning->id, $unit?->slot_id);
        $this->assertSame('Morning departure', $unit?->slot_label);

        // Renamed later, and the stay still says what it was sold as.
        $this->morning->update(['label' => 'Sunrise ride']);

        $this->assertSame('Morning departure', $unit?->refresh()->slot_label);
    }

    public function test_a_departure_can_cost_more_than_the_one_before_it(): void
    {
        $plan = RatePlan::create([
            'listing_id' => $this->listing->id,
            'name' => 'Standard rate',
            'code' => 'STD',
            'pricing_strategy' => PricingStrategy::PerUnit,
            'is_default' => true,
        ]);

        // The sunset drive is dearer than the morning one, which is the
        // ordinary case a rate keyed by date alone could not express.
        InventoryWriteGuard::allow(fn () => RatePlanDay::create([
            'rate_plan_id' => $plan->id,
            'bookable_unit_id' => $this->unit->id,
            'slot_id' => $this->afternoon->id,
            'date' => '2026-09-10',
            'rate' => 1400,
        ]));

        $calendar = app(AvailabilityCalendar::class);

        $this->assertSame(1400.0, $calendar->rateForSlot($this->unit, Carbon::parse('2026-09-10'), $this->afternoon, $plan));

        // The morning has no rate of its own and falls back to the day, which
        // falls back to the unit — the three steps that were already there.
        $this->assertSame(950.0, $calendar->rateForSlot($this->unit, Carbon::parse('2026-09-10'), $this->morning, $plan));

        // And a booking is priced at what its own departure costs.
        $this->assertSame(2800.0, $this->book($this->afternoon, seats: 2)->total_amount);
    }

    /**
     * The bug CI caught, kept caught.
     *
     * Every reader that answers "what does this day cost / how full is this
     * day" has to ignore departure rows. Without that, a tour operator's 14:00
     * price answers the question for the whole day — and for the 09:00
     * departure too, since that one falls back to the day. The same applies in
     * reverse to a lodge's bulk season edit, which must not reach into a
     * departure's price.
     */
    public function test_a_departures_price_never_answers_for_the_day(): void
    {
        $plan = RatePlan::create([
            'listing_id' => $this->listing->id,
            'name' => 'Standard rate',
            'code' => 'STD',
            'pricing_strategy' => PricingStrategy::PerUnit,
            'is_default' => true,
        ]);

        InventoryWriteGuard::allow(fn () => RatePlanDay::create([
            'rate_plan_id' => $plan->id,
            'bookable_unit_id' => $this->unit->id,
            'slot_id' => $this->afternoon->id,
            'date' => '2026-09-10',
            'rate' => 1400,
        ]));

        $calendar = app(AvailabilityCalendar::class);

        // The day is still the unit's own rate, not the departure's.
        $this->assertSame(950.0, $calendar->rateFor($this->unit, Carbon::parse('2026-09-10'), $plan));

        // A season written across the day leaves the departure alone.
        app(InventoryWriter::class)->setRates(
            $plan,
            $this->unit,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-10'),
            ['rate' => 1100],
        );

        $this->assertSame(1100.0, $calendar->rateFor($this->unit, Carbon::parse('2026-09-10'), $plan));
        $this->assertSame(1400.0, $calendar->rateForSlot($this->unit, Carbon::parse('2026-09-10'), $this->afternoon, $plan));

        // And the seats a departure sold are not the property's units.
        $this->book($this->morning, seats: 3);

        $this->assertSame(8, $calendar->unitsFree($this->unit, Carbon::parse('2026-09-10')));
        $this->assertSame(5, $calendar->seatsFree($this->unit, Carbon::parse('2026-09-10'), $this->morning));
    }

    public function test_a_night_is_still_a_night(): void
    {
        // The same writer, the same counter, no departure: a lodge notices
        // nothing about any of this.
        $stay = $this->book(null, seats: 1);

        $this->assertNull($stay->units->first()?->slot_id);
        $this->assertSame(7, app(AvailabilityCalendar::class)->unitsFree($this->unit, Carbon::parse('2026-09-10')));
        $this->assertSame(8, app(AvailabilityCalendar::class)->seatsFree($this->unit, Carbon::parse('2026-09-10'), $this->morning));
    }

    private function slot(string $time, string $label): BookingSlot
    {
        return BookingSlot::create([
            'bookable_unit_id' => $this->unit->id,
            'label' => $label,
            'starts_at' => $time,
            'duration_minutes' => 180,
        ]);
    }

    private function book(?BookingSlot $slot, int $seats): Reservation
    {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $this->listing,
            lines: [new BookingLine(
                $this->unit,
                $seats,
                Carbon::parse('2026-09-10'),
                Carbon::parse('2026-09-11'),
                slot: $slot,
            )],
            guestName: 'Guest',
            source: ReservationSource::WalkIn,
            notify: false,
        ));
    }
}
