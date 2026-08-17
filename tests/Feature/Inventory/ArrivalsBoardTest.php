<?php

namespace Tests\Feature\Inventory;

use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Models\BookableUnit;
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
 * The morning board: three movements on one date, told apart correctly.
 *
 * The half-open rule is the thing most likely to be got wrong here — a stay
 * ending today is a departure and is not in house, so a room turning over the
 * same day is counted once on each side and never twice in one.
 */
class ArrivalsBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_one_date_with_an_arrival_a_departure_and_a_stay_in_progress(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing, ['total_units' => 6]);

        $this->book($listing, $room, '2026-09-10', '2026-09-13', guest: 'Arriving Today');
        $this->book($listing, $room, '2026-09-07', '2026-09-10', guest: 'Leaving Today');
        $this->book($listing, $room, '2026-09-08', '2026-09-12', guest: 'Staying On');
        $this->book($listing, $room, '2026-09-12', '2026-09-14', guest: 'Not Yet Here');

        $board = app(ArrivalsBoard::class)->forDate($listing, Carbon::parse('2026-09-10'));

        $this->assertSame(['Arriving Today'], $board->arrivals->pluck('guest_name')->all());
        $this->assertSame(['Leaving Today'], $board->departures->pluck('guest_name')->all());
        $this->assertSame(['Staying On'], $board->inHouse->pluck('guest_name')->all());
    }

    public function test_a_room_turning_over_the_same_day_is_a_departure_and_an_arrival_not_a_stay(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing, ['total_units' => 2]);

        $out = $this->book($listing, $room, '2026-09-08', '2026-09-10', guest: 'Out');
        $in = $this->book($listing, $room, '2026-09-10', '2026-09-12', guest: 'In');

        $board = app(ArrivalsBoard::class)->forDate($listing, Carbon::parse('2026-09-10'));

        $this->assertTrue($board->departures->contains('id', $out->id));
        $this->assertTrue($board->arrivals->contains('id', $in->id));
        $this->assertTrue($board->inHouse->isEmpty());
    }

    public function test_rooms_and_guests_tonight_count_arrivals_and_stays_but_not_departures(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing, ['total_units' => 8, 'max_adults' => 4]);

        $this->book($listing, $room, '2026-09-10', '2026-09-12', quantity: 2, guest: 'Arriving', adults: 3);
        $this->book($listing, $room, '2026-09-08', '2026-09-12', quantity: 1, guest: 'Staying', adults: 2, children: 1);
        $this->book($listing, $room, '2026-09-08', '2026-09-10', quantity: 3, guest: 'Leaving', adults: 4);

        $board = app(ArrivalsBoard::class)->forDate($listing, Carbon::parse('2026-09-10'));

        $this->assertSame(3, $board->unitsStayingTonight());
        $this->assertSame(6, $board->guestsStayingTonight());
    }

    public function test_a_cancelled_stay_is_off_the_board_and_a_no_show_is_not(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing, ['total_units' => 4]);
        $writer = app(InventoryWriter::class);

        $cancelled = $this->book($listing, $room, '2026-09-10', '2026-09-12', guest: 'Cancelled');
        $writer->cancel($cancelled, 'Changed plans');

        $noShow = $this->book($listing, $room, '2026-09-10', '2026-09-12', guest: 'No Show');
        $writer->transition($noShow, StayStatus::NoShow);

        $board = app(ArrivalsBoard::class)->forDate($listing, Carbon::parse('2026-09-10'));

        $this->assertSame(['No Show'], $board->arrivals->pluck('guest_name')->all());
    }

    public function test_the_board_shows_only_the_property_it_was_asked_about(): void
    {
        $mine = $this->property();
        $theirs = $this->property();

        $this->book($mine, $this->bookableUnit($mine), '2026-09-10', '2026-09-12', guest: 'My Guest');
        $this->book($theirs, $this->bookableUnit($theirs), '2026-09-10', '2026-09-12', guest: 'Their Guest');

        $board = app(ArrivalsBoard::class)->forDate($mine, Carbon::parse('2026-09-10'));

        $this->assertSame(['My Guest'], $board->arrivals->pluck('guest_name')->all());
    }

    public function test_a_multi_room_stay_is_one_row_with_every_bookable_unit_on_it(): void
    {
        $listing = $this->property();
        $standard = $this->bookableUnit($listing, ['name' => 'Standard', 'total_units' => 4]);
        $family = $this->bookableUnit($listing, ['name' => 'Family', 'total_units' => 2]);

        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [
                new BookingLine($standard, 2, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-12')),
                new BookingLine($family, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-12')),
            ],
            guestName: 'Family Party',
            source: ReservationSource::Telephone,
            adults: 4,
            children: 2,
        ));

        $board = app(ArrivalsBoard::class)->forDate($listing, Carbon::parse('2026-09-10'));

        $this->assertCount(1, $board->arrivals);
        $this->assertSame(3, $board->units($board->arrivals));
        $this->assertEqualsCanonicalizing(
            ['Standard', 'Family'],
            $board->arrivals->first()->units->map(fn ($unit) => $unit->bookableUnit->name)->all()
        );
    }

    private function property(): Listing
    {
        return Listing::factory()->create(['type' => ListingType::Accommodation]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function bookableUnit(Listing $listing, array $attributes = []): BookableUnit
    {
        return BookableUnit::factory()->create(array_merge([
            'listing_id' => $listing->id,
            'total_units' => 3,
            'base_rate' => 1500.00,
            'currency' => 'NAD',
        ], $attributes));
    }

    private function book(
        Listing $listing,
        BookableUnit $bookableUnit,
        string $checkIn,
        string $checkOut,
        int $quantity = 1,
        string $guest = 'Test Guest',
        int $adults = 2,
        int $children = 0,
    ): Reservation {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($bookableUnit, $quantity, Carbon::parse($checkIn), Carbon::parse($checkOut))],
            guestName: $guest,
            source: ReservationSource::PartnerEntered,
            adults: $adults,
            children: $children,
        ));
    }
}
