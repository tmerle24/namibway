<?php

namespace Tests\Feature\Inventory;

use App\Enums\ReservationSource;
use App\Exceptions\Inventory\InventoryUnavailableException;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
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

    private RoomType $unit;

    private BookingSlot $morning;

    private BookingSlot $afternoon;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 09:00:00'));

        $this->listing = Listing::factory()->create();
        $this->unit = RoomType::factory()->create([
            'listing_id' => $this->listing->id,
            'name' => 'Quad tour',
            'total_units' => 8,
            'rate_per_night' => 950,
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
            'room_type_id' => $this->unit->id,
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
