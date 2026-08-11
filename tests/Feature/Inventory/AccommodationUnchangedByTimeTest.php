<?php

namespace Tests\Feature\Inventory;

use App\Enums\PricingStrategy;
use App\Enums\ReservationSource;
use App\Models\BookableUnit;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\RatePlanDay;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DayGrid;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\DTOs\OccupancyGridData;
use App\Services\Inventory\InventoryWriteGuard;
use App\Services\Inventory\InventoryWriter;
use App\Services\Inventory\OccupancyGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The guard on the whole of "time inside a day": **a property that sells
 * nights must not notice that departures exist.**
 *
 * This is the same kind of test as the one that used to sit on the two
 * availability readers before they were joined, and it is here for the same
 * reason: the change it guards against is not a bug somebody would spot in
 * review, it is a leak. A departure's row shares a table, a date and a unit
 * with a night's row, and every reader that forgets to say which of the two it
 * wants gets a plausible number back. On a repository where a green push to
 * main ships straight to production, "plausible" is the dangerous kind of
 * wrong: nobody looks twice at a calendar that reads eight free.
 *
 * Two of the assertions below started red. `AvailabilityCalendar::snapshot()`
 * — the bulk read the whole occupancy grid is built on — fetched both kinds of
 * row and keyed them by date alone, so whichever the database returned last
 * silently became the day. `rateDaysKeyedByDate()`, which prices a stay, did
 * the same.
 */
class AccommodationUnchangedByTimeTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $start;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));
        $this->start = Carbon::parse('2026-09-01')->startOfDay();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_lodge_has_no_hour_axis_at_all(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->bookableUnit($listing, ['name' => 'Standard Chalet', 'total_units' => 4]);

        $this->book($listing, $room, '2026-09-03', '2026-09-06', quantity: 2, guest: 'Anna Shipanga');

        $this->assertNull(
            app(DayGrid::class)->build($listing, $this->start),
            'A property with no timetable must produce no day view, not an empty one.',
        );

        // And every cell of its own grid still counts rooms on nights.
        foreach ($this->grid($listing)->rows[0]->cells as $cell) {
            $this->assertFalse($cell->isDepartureDay());
            $this->assertSame(0, $cell->departures);
        }
    }

    public function test_a_lodges_grid_reads_exactly_what_the_calendar_says(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->bookableUnit($listing, ['total_units' => 3, 'rate_per_night' => 1800]);

        $this->book($listing, $room, '2026-09-04', '2026-09-06', quantity: 2, guest: 'Guest');

        // Somebody else's tour, on the same dates, in the same tables.
        $this->tourOperator();

        $calendar = app(AvailabilityCalendar::class);
        $row = $this->grid($listing)->rows[0];

        foreach ($row->cells as $cell) {
            $this->assertSame(
                $calendar->unitsFree($room, $cell->date),
                $cell->unitsFree,
                'The grid and the single-night read must agree on '.$cell->date->toDateString(),
            );
            $this->assertSame($calendar->rateFor($room, $cell->date), $cell->rate);
        }

        $this->assertSame(1, $row->cells[3]->unitsFree);
        $this->assertSame('Guest', $row->lanes[0][0]->label);
    }

    /**
     * The leak this file exists for, on the one property where the two kinds of
     * row genuinely sit side by side.
     */
    public function test_a_departures_row_never_becomes_the_days_row_in_a_bulk_read(): void
    {
        $listing = Listing::factory()->create();
        $chalet = $this->bookableUnit($listing, ['name' => 'Chalet', 'total_units' => 3, 'rate_per_night' => 1800]);
        $tour = $this->bookableUnit($listing, ['name' => 'Quad tour', 'total_units' => 8, 'rate_per_night' => 950]);

        $plan = $this->ratePlan($listing);
        $morning = $this->slot($tour, '09:00', 'Morning departure');

        // The departure is dearer than the day, and sells seats the property's
        // own counter knows nothing about.
        InventoryWriteGuard::allow(fn () => RatePlanDay::create([
            'rate_plan_id' => $plan->id,
            'bookable_unit_id' => $tour->id,
            'slot_id' => $morning->id,
            'date' => '2026-09-04',
            'rate' => 1400,
        ]));

        $this->book($listing, $tour, '2026-09-04', '2026-09-05', quantity: 3, guest: 'Seat Guest', slot: $morning);

        $rows = collect($this->grid($listing, plan: $plan)->rows)->keyBy(fn ($row) => $row->bookableUnit->name);

        // The chalet is a lodge in every sense that matters here.
        $chaletCell = $rows['Chalet']->cells[3];
        $this->assertSame(3, $chaletCell->unitsFree);
        $this->assertSame(1800.0, $chaletCell->rate);
        $this->assertFalse($chaletCell->isDepartureDay());

        // Both the seats and the departure's price stay on the departure. Read
        // as the day they would say "5 of 8 quads free at N$1,400 a night".
        $this->assertSame(8, app(AvailabilityCalendar::class)->unitsFree($tour, Carbon::parse('2026-09-04')));
        $this->assertSame(950.0, app(AvailabilityCalendar::class)->rateFor($tour, Carbon::parse('2026-09-04'), $plan));

        // And a stay priced by the night on that same unit is priced at the
        // day's rate, not at what the 09:00 tour charges for a seat.
        $this->assertSame(
            950.0,
            app(AvailabilityCalendar::class)->quote($tour, Carbon::parse('2026-09-04'), Carbon::parse('2026-09-05'), ratePlan: $plan)->total(),
        );

        // A seat sale is not a bar: drawn as one it would be a chip a column
        // wide, and a busy month would stack a lane per booking.
        $this->assertSame([], $rows['Quad tour']->lanes);
    }

    /**
     * The route the leak actually reaches a night by, and the reason the two
     * kinds of row are filed apart rather than merely read apart.
     *
     * An operator retires a departure and goes back to selling the unit by the
     * period. The departure's rows stay — they are what last month sold — and
     * the unit now has no timetable, so it is read as a night again. If the
     * bulk read keyed rows by date alone, the retired 09:00 tour would answer
     * for the night: five of eight free at N$1,400, on a unit that is empty and
     * costs N$950.
     */
    public function test_a_retired_departure_does_not_start_answering_for_the_night(): void
    {
        $listing = Listing::factory()->create();
        $unit = $this->bookableUnit($listing, ['name' => 'Quad', 'total_units' => 8, 'rate_per_night' => 950]);
        $plan = $this->ratePlan($listing);
        $morning = $this->slot($unit, '09:00', 'Morning departure');

        InventoryWriteGuard::allow(fn () => RatePlanDay::create([
            'rate_plan_id' => $plan->id,
            'bookable_unit_id' => $unit->id,
            'slot_id' => $morning->id,
            'date' => '2026-09-04',
            'rate' => 1400,
        ]));

        $this->book($listing, $unit, '2026-09-04', '2026-09-05', quantity: 3, guest: 'Seat Guest', slot: $morning);

        $morning->update(['is_active' => false]);

        $cell = $this->grid($listing, plan: $plan)->rows[0]->cells[3];

        $this->assertFalse($cell->isDepartureDay(), 'A unit with no active timetable is read as a night.');
        $this->assertSame(8, $cell->unitsFree);
        $this->assertSame(950.0, $cell->rate);

        // And the column total under it, which sums the cells.
        $this->assertSame(8, $this->grid($listing, plan: $plan)->columns[3]->unitsFree);
    }

    public function test_a_lodge_that_shares_a_property_with_a_tour_keeps_its_own_screen(): void
    {
        $listing = Listing::factory()->create();
        $chalet = $this->bookableUnit($listing, ['name' => 'Chalet', 'total_units' => 2]);
        $tour = $this->bookableUnit($listing, ['name' => 'Sunset drive', 'total_units' => 6]);
        $this->slot($tour, '17:00', 'Sunset drive');

        $names = array_map(fn ($row) => $row->bookableUnit->name, $this->grid($listing)->rows);

        // One calendar, both products — the thing BOOKING_SYSTEM.md insists on.
        $this->assertSame(['Chalet', 'Sunset drive'], $names);

        // In the day view the tour moves to the hour axis and stops being a
        // row, so the same day is never printed twice.
        $day = app(OccupancyGrid::class)->build($listing, $this->start, 1, null, omitDepartureUnits: true);

        $this->assertSame(['Chalet'], array_map(fn ($row) => $row->bookableUnit->name, $day->rows));
        $this->assertNotNull(app(DayGrid::class)->build($listing, $this->start));
        $this->assertSame($chalet->id, $day->rows[0]->bookableUnit->id);
    }

    private function grid(Listing $listing, ?RatePlan $plan = null): OccupancyGridData
    {
        return app(OccupancyGrid::class)->build($listing, $this->start, 30, $plan);
    }

    /** A second property that sells only departures, on the same dates. */
    private function tourOperator(): Listing
    {
        $listing = Listing::factory()->create();
        $unit = $this->bookableUnit($listing, ['name' => 'Quad tour', 'total_units' => 8]);
        $slot = $this->slot($unit, '09:00', 'Morning departure');

        $this->book($listing, $unit, '2026-09-04', '2026-09-05', quantity: 6, guest: 'Seat Guest', slot: $slot);

        return $listing;
    }

    private function slot(BookableUnit $unit, string $time, string $label, int $minutes = 180): BookingSlot
    {
        return BookingSlot::create([
            'bookable_unit_id' => $unit->id,
            'label' => $label,
            'starts_at' => $time,
            'duration_minutes' => $minutes,
        ]);
    }

    private function ratePlan(Listing $listing): RatePlan
    {
        return RatePlan::create([
            'listing_id' => $listing->id,
            'name' => 'Standard rate',
            'code' => 'STD',
            'pricing_strategy' => PricingStrategy::PerUnit,
            'is_default' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function bookableUnit(Listing $listing, array $attributes = []): BookableUnit
    {
        return BookableUnit::factory()->create(array_merge([
            'listing_id' => $listing->id,
            'total_units' => 2,
            'rate_per_night' => 1200,
            'currency' => 'NAD',
        ], $attributes));
    }

    private function book(
        Listing $listing,
        BookableUnit $room,
        string $checkIn,
        string $checkOut,
        int $quantity = 1,
        string $guest = 'Guest',
        ?BookingSlot $slot = null,
    ): void {
        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, $quantity, Carbon::parse($checkIn), Carbon::parse($checkOut), slot: $slot)],
            guestName: $guest,
            source: ReservationSource::WalkIn,
            notify: false,
        ));
    }
}
