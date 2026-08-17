<?php

namespace Tests\Feature\Inventory;

use App\Enums\BlockReason;
use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\Reservation;
use App\Services\Inventory\DTOs\BlockRequest;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\DTOs\OccupancyBar;
use App\Services\Inventory\DTOs\OccupancyGridData;
use App\Services\Inventory\InventoryWriter;
use App\Services\Inventory\OccupancyGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the occupancy screen has to get right: bars land on the nights they
 * cover, a season prices per night, a block is not a sale, and the whole month
 * costs the same number of queries whether the lodge has three room types or
 * twenty.
 */
class OccupancyGridTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $start;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed and mid-day so "the property's today" and the test's today are
        // the same date in Africa/Windhoek (UTC+2) as well as in UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));
        $this->start = Carbon::parse('2026-09-01')->startOfDay();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_stay_becomes_a_bar_on_the_nights_it_covers(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing, ['total_units' => 4]);

        $this->book($listing, $room, '2026-09-03', '2026-09-06', quantity: 2, guest: 'Anna Shipanga');

        $grid = $this->grid($listing);
        $row = $grid->rows[0];
        $bar = $row->lanes[0][0];

        $this->assertSame(OccupancyBar::KIND_RESERVATION, $bar->kind);
        $this->assertSame('Anna Shipanga', $bar->label);
        $this->assertSame(2, $bar->units);
        $this->assertSame(2, $bar->startIndex, 'The 3rd is the third column of a range starting on the 1st.');
        $this->assertSame(3, $bar->span, 'The 3rd to the 6th is three nights, not four days.');
        $this->assertFalse($bar->clippedStart);
        $this->assertFalse($bar->clippedEnd);

        // The check-out day is not a night: the 6th is free again.
        $this->assertSame(2, $row->cells[2]->unitsFree);
        $this->assertSame(2, $row->cells[4]->unitsFree);
        $this->assertSame(4, $row->cells[5]->unitsFree);
    }

    public function test_a_stay_crossing_a_season_boundary_shows_each_nights_own_rate(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing, ['base_rate' => 1000]);

        $writer = app(InventoryWriter::class);
        $writer->setCalendar($room, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-04'), ['rate' => 1000.00]);
        $writer->setCalendar($room, Carbon::parse('2026-09-05'), Carbon::parse('2026-09-30'), ['rate' => 1600.00]);

        $this->book($listing, $room, '2026-09-03', '2026-09-07');

        $cells = $this->grid($listing)->rows[0]->cells;

        $this->assertSame(1000.00, $cells[2]->rate, 'The 3rd is low season.');
        $this->assertSame(1000.00, $cells[3]->rate, 'The 4th is the last low-season night.');
        $this->assertSame(1600.00, $cells[4]->rate, 'The 5th is the first high-season night.');
        $this->assertSame(1600.00, $cells[6]->rate);
    }

    public function test_a_night_with_no_calendar_row_falls_back_to_the_bookable_unit(): void
    {
        $listing = $this->property();
        $this->bookableUnit($listing, ['total_units' => 5, 'base_rate' => 2250]);

        $cells = $this->grid($listing)->rows[0]->cells;

        $this->assertSame(0, DB::table('bookable_unit_calendar_days')->count(), 'A sparse calendar is the normal state.');
        $this->assertSame(5, $cells[10]->capacity);
        $this->assertSame(5, $cells[10]->unitsFree);
        $this->assertSame(2250.00, $cells[10]->rate);
    }

    public function test_a_block_is_drawn_apart_from_a_sale_and_still_takes_the_room(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing, ['total_units' => 3]);

        app(InventoryWriter::class)->block(new BlockRequest(
            bookableUnit: $room,
            units: 2,
            firstNight: Carbon::parse('2026-09-04'),
            lastNight: Carbon::parse('2026-09-06'),
            reason: BlockReason::Maintenance,
            note: 'Re-thatching.',
        ));

        $row = $this->grid($listing)->rows[0];
        $bar = $row->lanes[0][0];

        $this->assertSame(OccupancyBar::KIND_BLOCK, $bar->kind);
        $this->assertSame('Maintenance', $bar->label);
        $this->assertSame(3, $bar->startIndex);
        $this->assertSame(3, $bar->span, 'Both ends of a block are nights, so the 4th to the 6th is three.');

        $this->assertSame(0, $row->cells[3]->unitsSold, 'A block is not a sale.');
        $this->assertSame(2, $row->cells[3]->unitsBlocked);
        $this->assertSame(1, $row->cells[3]->unitsFree);
        $this->assertSame(3, $row->cells[6]->unitsFree, 'The night after the last blocked one is free again.');
    }

    public function test_a_stay_running_past_both_edges_is_clipped_and_says_so(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing);

        $this->book($listing, $room, '2026-08-25', '2026-10-15');

        $grid = $this->grid($listing, days: 30);
        $bar = $grid->rows[0]->lanes[0][0];

        $this->assertSame(0, $bar->startIndex);
        $this->assertSame(30, $bar->span);
        $this->assertTrue($bar->clippedStart);
        $this->assertTrue($bar->clippedEnd);
    }

    public function test_overlapping_stays_are_stacked_into_separate_lanes(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing, ['total_units' => 4]);

        $this->book($listing, $room, '2026-09-02', '2026-09-06', guest: 'First');
        $this->book($listing, $room, '2026-09-04', '2026-09-08', guest: 'Second');
        // Starts the day the first one ends — half-open, so it shares a lane.
        $this->book($listing, $room, '2026-09-06', '2026-09-09', guest: 'Third');

        $lanes = $this->grid($listing)->rows[0]->lanes;

        $this->assertCount(2, $lanes);
        $this->assertSame(['First', 'Third'], array_map(fn (OccupancyBar $bar) => $bar->label, $lanes[0]));
        $this->assertSame(['Second'], array_map(fn (OccupancyBar $bar) => $bar->label, $lanes[1]));
    }

    public function test_a_month_costs_the_same_number_of_queries_at_three_bookable_units_and_at_twenty(): void
    {
        $small = $this->property();
        $room = $this->bookableUnit($small);

        for ($i = 0; $i < 2; $i++) {
            $this->bookableUnit($small);
        }

        $this->book($small, $room, '2026-09-05', '2026-09-08');

        $large = $this->property();
        $rooms = [];

        for ($i = 0; $i < 20; $i++) {
            $rooms[] = $this->bookableUnit($large);
        }

        foreach ($rooms as $index => $each) {
            $this->book($large, $each, '2026-09-0'.(($index % 5) + 1), '2026-09-0'.(($index % 5) + 3));
        }

        $forThree = $this->countQueries(fn () => $this->grid($small, days: 31));
        $forTwenty = $this->countQueries(fn () => $this->grid($large, days: 31));

        $this->assertSame(
            $forThree,
            $forTwenty,
            'A month must be a fixed number of queries; anything per room type or per cell grows here.'
        );

        // Room types, calendar nights, reservation units, their reservations,
        // blocks, and the listing's own country lookup. Ten is head-room, not
        // a target — the point of the assertion above is that it does not grow.
        $this->assertLessThanOrEqual(10, $forTwenty);
    }

    public function test_it_shows_only_the_property_it_was_asked_about(): void
    {
        $mine = $this->property();
        $myRoom = $this->bookableUnit($mine);
        $this->book($mine, $myRoom, '2026-09-03', '2026-09-05', guest: 'My Guest');

        $theirs = $this->property();
        $theirRoom = $this->bookableUnit($theirs);
        $this->book($theirs, $theirRoom, '2026-09-03', '2026-09-05', guest: 'Their Guest');

        $grid = $this->grid($mine);
        $labels = [];

        foreach ($grid->rows as $row) {
            foreach ($row->lanes as $lane) {
                foreach ($lane as $bar) {
                    $labels[] = $bar->label;
                }
            }
        }

        $this->assertSame(['My Guest'], $labels);
        $this->assertCount(1, $grid->rows);
    }

    public function test_a_retired_bookable_unit_is_hidden_unless_it_still_has_a_stay_on_screen(): void
    {
        $listing = $this->property();
        $quiet = $this->bookableUnit($listing, ['name' => 'Aardvark Suite', 'is_active' => false]);
        $busy = $this->bookableUnit($listing, ['name' => 'Baobab Suite', 'is_active' => false]);

        $this->book($listing, $busy, '2026-09-03', '2026-09-05', guest: 'Late Guest');

        $names = array_map(fn ($row) => $row->bookableUnit->name, $this->grid($listing)->rows);

        $this->assertSame(['Baobab Suite'], $names, 'Hiding a retired room type must never hide a booking.');
        $this->assertNotContains($quiet->name, $names);
    }

    public function test_lowering_capacity_under_what_is_already_sold_reads_as_overbooked(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing, ['total_units' => 3]);

        $this->book($listing, $room, '2026-09-04', '2026-09-06', quantity: 3);

        // The route an overbooking actually arrives by: the stays are already
        // in the book and the room type shrinks under them.
        $room->update(['total_units' => 1]);

        $cell = $this->grid($listing)->rows[0]->cells[3];

        $this->assertSame(-2, $cell->unitsFree);
        $this->assertTrue($cell->isOverbooked());
        $this->assertFalse($cell->isSoldOut(), 'Overbooked is worse than sold out and must not be reported as it.');
    }

    public function test_restrictions_reach_the_cells_that_carry_them(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing);

        $writer = app(InventoryWriter::class);
        $writer->setCalendar($room, Carbon::parse('2026-09-04'), Carbon::parse('2026-09-04'), [
            'closed_to_arrival' => true,
            'min_stay' => 3,
        ]);
        $writer->setCalendar($room, Carbon::parse('2026-09-07'), Carbon::parse('2026-09-07'), [
            'closed_to_departure' => true,
        ]);

        $cells = $this->grid($listing)->rows[0]->cells;

        $this->assertTrue($cells[3]->closedToArrival);
        $this->assertSame(3, $cells[3]->minStay);
        $this->assertTrue($cells[3]->hasRestriction());
        $this->assertTrue($cells[6]->closedToDeparture);
        $this->assertFalse($cells[4]->hasRestriction());
    }

    public function test_a_cancelled_stay_leaves_the_calendar(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing, ['total_units' => 2]);

        $reservation = $this->book($listing, $room, '2026-09-03', '2026-09-05', guest: 'Gone Away');
        app(InventoryWriter::class)->cancel($reservation, 'Changed plans');

        $row = $this->grid($listing)->rows[0];

        $this->assertSame([], $row->lanes);
        $this->assertSame(2, $row->cells[2]->unitsFree);
    }

    public function test_a_no_show_stays_on_the_calendar_because_the_room_was_held(): void
    {
        $listing = $this->property();
        $room = $this->bookableUnit($listing, ['total_units' => 2]);

        $reservation = $this->book($listing, $room, '2026-09-03', '2026-09-05', guest: 'Never Came');
        app(InventoryWriter::class)->transition($reservation, StayStatus::NoShow);

        $row = $this->grid($listing)->rows[0];

        $this->assertSame('Never Came', $row->lanes[0][0]->label);
        $this->assertSame(1, $row->cells[2]->unitsFree);
    }

    private function grid(Listing $listing, int $days = 30): OccupancyGridData
    {
        return app(OccupancyGrid::class)->build($listing, $this->start, $days);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
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
    ): Reservation {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($bookableUnit, $quantity, Carbon::parse($checkIn), Carbon::parse($checkOut))],
            guestName: $guest,
            source: ReservationSource::PartnerEntered,
        ));
    }
}
