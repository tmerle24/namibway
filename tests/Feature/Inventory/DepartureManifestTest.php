<?php

namespace Tests\Feature\Inventory;

use App\Enums\BoardBasis;
use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Models\BookableUnit;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\Reservation;
use App\Services\Inventory\ArrivalsBoard;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The board a tour operator opens every morning: a passenger list per
 * departure.
 *
 * The board was night-shaped, and for a seat that produced a plausible lie — a
 * booking for three places on the morning ride read as "3 rooms arriving", a
 * number a desk would lay tables from. So the two questions this file asks are
 * "who is on the 09:00" and "what does a seat do to the room counts", and the
 * answer to the second is nothing.
 */
class DepartureManifestTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $date;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 06:00:00'));
        $this->date = Carbon::parse('2026-09-10')->startOfDay();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_lodge_has_no_manifests_and_reads_exactly_as_it_did(): void
    {
        $listing = $this->property();
        $chalet = $this->unit($listing, 'Bush Chalet', 6);

        $this->book($listing, $chalet, quantity: 2, guest: 'Familie Braun', nights: 3);

        $board = app(ArrivalsBoard::class)->forDate($listing, $this->date);

        $this->assertFalse($board->hasManifests());
        $this->assertSame([], $board->runningToday());
        $this->assertSame(0, $board->seatsToday());
        $this->assertTrue($board->sellsNights);

        $this->assertCount(1, $board->arrivals);
        $this->assertSame(2, $board->units($board->arrivals));
        $this->assertSame(2, $board->unitsStayingTonight());
    }

    public function test_each_departure_gets_its_own_passenger_list_in_time_order(): void
    {
        $listing = $this->property();
        $tour = $this->unit($listing, 'Quad tour', 8);
        $afternoon = $this->slot($tour, '14:00', 'Afternoon ride');
        $morning = $this->slot($tour, '09:00', 'Morning ride');

        $this->book($listing, $tour, quantity: 3, guest: 'Anna Shipanga', slot: $morning, phone: '+264 81 000 0000');
        $this->book($listing, $tour, quantity: 2, guest: 'Klaus Weber', slot: $morning);
        $this->book($listing, $tour, quantity: 4, guest: 'The Petersens', slot: $afternoon);

        $manifests = app(ArrivalsBoard::class)->forDate($listing, $this->date)->runningToday();

        $this->assertCount(2, $manifests);

        // Time order down the page, whatever order the timetable was entered
        // in: a manifest is worked through in the order the vehicles leave.
        $this->assertSame('Morning ride', $manifests[0]->label());
        $this->assertSame('Afternoon ride', $manifests[1]->label());

        $this->assertSame(5, $manifests[0]->seatsSold);
        $this->assertSame(8, $manifests[0]->capacity);
        $this->assertSame(['Anna Shipanga', 'Klaus Weber'], array_map(fn ($stay) => $stay->label, $manifests[0]->stays));
        $this->assertSame('+264 81 000 0000', $manifests[0]->stays[0]->phone);
        $this->assertSame(3, $manifests[0]->stays[0]->seats);

        $this->assertSame(['The Petersens'], array_map(fn ($stay) => $stay->label, $manifests[1]->stays));
    }

    /** An empty departure is on the calendar, where a free seat count is the point. */
    public function test_a_departure_nobody_booked_is_not_a_page_of_nothing(): void
    {
        $listing = $this->property();
        $tour = $this->unit($listing, 'Quad tour', 8);
        $morning = $this->slot($tour, '09:00', 'Morning ride');
        $this->slot($tour, '14:00', 'Afternoon ride');

        $this->book($listing, $tour, quantity: 1, guest: 'Only One', slot: $morning);

        $board = app(ArrivalsBoard::class)->forDate($listing, $this->date);

        $this->assertCount(1, $board->runningToday());
        $this->assertSame(1, $board->seatsToday());
        // Both are still on the calendar, which is a different question.
        $this->assertSame(2, $board->manifests?->columnCount());
    }

    /**
     * The lie the board used to tell. Nobody checks in to a quad tour.
     */
    public function test_a_seat_only_booking_is_not_an_arrival(): void
    {
        $listing = $this->property();
        $tour = $this->unit($listing, 'Quad tour', 8);
        $morning = $this->slot($tour, '09:00', 'Morning ride');

        $this->book($listing, $tour, quantity: 3, guest: 'Anna Shipanga', slot: $morning);

        $board = app(ArrivalsBoard::class)->forDate($listing, $this->date);

        $this->assertCount(0, $board->arrivals);
        $this->assertCount(0, $board->departures);
        $this->assertCount(0, $board->inHouse);
        $this->assertSame(0, $board->unitsStayingTonight());
        $this->assertSame(0, $board->guestsStayingTonight());

        // But the day is emphatically not empty.
        $this->assertFalse($board->isEmpty());
        $this->assertSame(3, $board->seatsToday());
        $this->assertSame('Anna Shipanga', $board->runningToday()[0]->stays[0]->label);
    }

    /**
     * A guest with a chalet and a place on the ride is on both — they really
     * do arrive — but the room count is one room and not four.
     */
    public function test_a_mixed_booking_arrives_with_its_rooms_only(): void
    {
        $listing = $this->property();
        $chalet = $this->unit($listing, 'Bush Chalet', 6);
        $tour = $this->unit($listing, 'Quad tour', 8);
        $morning = $this->slot($tour, '09:00', 'Morning ride');

        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [
                new BookingLine($chalet, 1, $this->date, $this->date->copy()->addDays(2)),
                new BookingLine($tour, 3, $this->date, $this->date->copy()->addDay(), slot: $morning),
            ],
            guestName: 'Familie Braun',
            source: ReservationSource::WalkIn,
            adults: 2,
            children: 2,
            notify: false,
        ));

        $board = app(ArrivalsBoard::class)->forDate($listing, $this->date);

        $this->assertCount(1, $board->arrivals);
        $this->assertSame(1, $board->units($board->arrivals), 'Three seats are not three rooms.');
        $this->assertSame(1, $board->unitsStayingTonight());
        $this->assertSame(4, $board->guestsStayingTonight());

        // And on the manifest as well, because they are going on the ride.
        $this->assertSame('Familie Braun', $board->runningToday()[0]->stays[0]->label);
        $this->assertSame(3, $board->runningToday()[0]->stays[0]->seats);
    }

    /** A cancelled seat is off the list; a no-show is not — it was held. */
    public function test_a_cancelled_seat_leaves_the_manifest_and_a_no_show_stays(): void
    {
        $listing = $this->property();
        $tour = $this->unit($listing, 'Quad tour', 8);
        $morning = $this->slot($tour, '09:00', 'Morning ride');

        $gone = $this->book($listing, $tour, quantity: 2, guest: 'Changed Plans', slot: $morning);
        $absent = $this->book($listing, $tour, quantity: 1, guest: 'Never Came', slot: $morning);

        app(InventoryWriter::class)->cancel($gone, 'Called to cancel');
        app(InventoryWriter::class)->transition($absent, StayStatus::NoShow);

        $names = array_map(
            fn ($stay) => $stay->label,
            app(ArrivalsBoard::class)->forDate($listing, $this->date)->runningToday()[0]->stays,
        );

        $this->assertSame(['Never Came'], $names);
    }

    /**
     * A property that sells nothing by the night is not shown three headings
     * saying "nobody" on every page it prints.
     */
    public function test_an_operator_who_only_runs_tours_is_not_asked_about_rooms(): void
    {
        $listing = $this->property();
        $tour = $this->unit($listing, 'Quad tour', 8);
        $this->slot($tour, '09:00', 'Morning ride');

        $this->assertFalse(app(ArrivalsBoard::class)->forDate($listing, $this->date)->sellsNights);

        // One chalet, and the room half of the board comes back.
        $this->unit($listing, 'Bush Chalet', 4);

        $this->assertTrue(app(ArrivalsBoard::class)->forDate($listing, $this->date)->sellsNights);
    }

    /** Board is a property of a room line, and a seat has none — see boardTonight(). */
    public function test_seats_do_not_reach_the_kitchens_count(): void
    {
        $listing = $this->property();
        $chalet = $this->unit($listing, 'Bush Chalet', 6);
        $tour = $this->unit($listing, 'Quad tour', 8);
        $morning = $this->slot($tour, '09:00', 'Morning ride');

        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [
                new BookingLine($chalet, 2, $this->date, $this->date->copy()->addDays(2)),
                new BookingLine($tour, 4, $this->date, $this->date->copy()->addDay(), slot: $morning),
            ],
            guestName: 'Familie Braun',
            source: ReservationSource::WalkIn,
            notify: false,
        ));

        $listing->reservations()->latest('id')->firstOrFail()
            ->units()->whereNull('slot_id')->update(['board_basis' => BoardBasis::HalfBoard->value]);

        $counts = app(ArrivalsBoard::class)->forDate($listing, $this->date)->boardTonight();

        $this->assertSame([BoardBasis::HalfBoard->shortLabel() => 2], $counts);
    }

    private function property(): Listing
    {
        return Listing::factory()->create(['type' => ListingType::Accommodation]);
    }

    private function unit(Listing $listing, string $name, int $units): BookableUnit
    {
        return BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'name' => $name,
            'total_units' => $units,
            'base_rate' => 950,
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

    private function book(
        Listing $listing,
        BookableUnit $unit,
        int $quantity,
        string $guest,
        ?BookingSlot $slot = null,
        int $nights = 1,
        ?string $phone = null,
    ): Reservation {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($unit, $quantity, $this->date, $this->date->copy()->addDays($nights), slot: $slot)],
            guestName: $guest,
            guestPhone: $phone,
            source: ReservationSource::WalkIn,
            notify: false,
        ));
    }
}
