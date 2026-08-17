<?php

namespace Tests\Feature\Inventory;

use App\Enums\PricingStrategy;
use App\Enums\ReservationSource;
use App\Models\BookableUnit;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\RatePlanDay;
use App\Services\Inventory\DayGrid;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\DTOs\DayGridData;
use App\Services\Inventory\InventoryWriteGuard;
use App\Services\Inventory\InventoryWriter;
use App\Services\Inventory\OccupancyGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The other reading: one day, the hour axis down the page, departures across.
 *
 * What it has to get right is placement and separation — a block sitting on the
 * rows it actually runs over, and each departure's seats belonging to it alone.
 * Everything about how the seats *move* is DepartureBookingTest's; this is the
 * picture of the result.
 */
class DayGridTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $date;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
        $this->date = Carbon::parse('2026-09-10')->startOfDay();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_axis_covers_the_timetable_and_nothing_else(): void
    {
        $listing = Listing::factory()->create();
        $unit = $this->unit($listing);
        $this->slot($unit, '09:00', 'Morning', 180);
        $this->slot($unit, '14:00', 'Afternoon', 180);

        $day = $this->day($listing);

        $this->assertSame('09:00', $day->axisStart->format('H:i'));
        // 09:00 to 17:00 at one row an hour.
        $this->assertSame(60, $day->resolutionMinutes);
        $this->assertSame(8, $day->rowCount);
    }

    public function test_the_resolution_is_the_coarsest_the_timetable_lands_on(): void
    {
        $listing = Listing::factory()->create();
        $unit = $this->unit($listing);
        $this->slot($unit, '09:30', 'Morning', 90);

        $this->assertSame(30, $this->day($listing)->resolutionMinutes);

        $this->slot($unit, '13:15', 'Quarter past', 45);

        $this->assertSame(15, $this->day($listing)->resolutionMinutes);

        // And an operator can still say otherwise.
        $this->assertSame(60, $this->day($listing, resolution: 60)->resolutionMinutes);
    }

    public function test_a_departure_sits_on_the_rows_it_runs_over(): void
    {
        $listing = Listing::factory()->create();
        $unit = $this->unit($listing);
        $this->slot($unit, '09:00', 'Morning', 90);
        $this->slot($unit, '13:30', 'Afternoon', 60);

        $day = $this->day($listing);

        // Half-hour rows, axis from 09:00.
        $this->assertSame(30, $day->resolutionMinutes);

        $morning = $day->columns[0];
        $this->assertSame(0, $morning->startIndex);
        $this->assertSame(3, $morning->span);

        $afternoon = $day->columns[1];
        $this->assertSame(9, $afternoon->startIndex);
        $this->assertSame(2, $afternoon->span);
        $this->assertFalse($afternoon->clippedEnd);
    }

    public function test_a_departure_running_past_midnight_is_cut_off_at_the_bottom(): void
    {
        $listing = Listing::factory()->create();
        $unit = $this->unit($listing);
        $this->slot($unit, '22:00', 'Night drive', 240);

        $day = $this->day($listing);
        $column = $day->columns[0];

        $this->assertTrue($column->clippedEnd);
        // The axis stops at midnight; the block stops with it.
        $this->assertSame(2, $day->rowCount);
        $this->assertSame(2, $column->span);
        $this->assertSame($day->rowCount, $column->startIndex + $column->span);
    }

    public function test_each_departure_carries_its_own_seats_price_and_passengers(): void
    {
        $listing = Listing::factory()->create();
        $unit = $this->unit($listing, ['total_units' => 8, 'base_rate' => 950]);
        $plan = $this->ratePlan($listing);
        $morning = $this->slot($unit, '09:00', 'Morning departure', 180);
        $sunset = $this->slot($unit, '17:00', 'Sunset drive', 180);

        InventoryWriteGuard::allow(fn () => RatePlanDay::create([
            'rate_plan_id' => $plan->id,
            'bookable_unit_id' => $unit->id,
            'slot_id' => $sunset->id,
            'date' => '2026-09-10',
            'rate' => 1400,
        ]));

        $this->book($listing, $unit, $morning, seats: 3, guest: 'Anna Shipanga');

        $day = $this->day($listing, plan: $plan);
        [$first, $second] = $day->columns;

        $this->assertSame('Morning departure', $first->label());
        $this->assertSame(5, $first->seatsFree);
        $this->assertSame(950.0, $first->rate);
        $this->assertSame('Anna Shipanga', $first->stays[0]->label);
        $this->assertSame(3, $first->stays[0]->seats);

        // The sunset drive is untouched and dearer, which is the whole reason a
        // departure is a row rather than a flag.
        $this->assertSame(8, $second->seatsFree);
        $this->assertSame(1400.0, $second->rate);
        $this->assertSame([], $second->stays);

        $this->assertSame(3, $day->seatsSold());
        $this->assertSame(16, $day->capacity());
    }

    public function test_a_sold_out_departure_says_so_without_closing_the_day(): void
    {
        $listing = Listing::factory()->create();
        $unit = $this->unit($listing, ['total_units' => 4]);
        $morning = $this->slot($unit, '09:00', 'Morning', 120);
        $this->slot($unit, '14:00', 'Afternoon', 120);

        $this->book($listing, $unit, $morning, seats: 4);

        [$first, $second] = $this->day($listing)->columns;

        $this->assertTrue($first->isSoldOut());
        $this->assertFalse($first->isOverbooked());
        $this->assertFalse($second->isSoldOut());
    }

    /**
     * What a week or a month can honestly say about a timetable. Without it a
     * tour operator's month reads "8 free" on every day forever, because its
     * counters are on the departures and the unit's own row is the one nobody
     * writes.
     */
    public function test_the_month_reading_sums_the_days_departures(): void
    {
        $listing = Listing::factory()->create();
        $unit = $this->unit($listing, ['total_units' => 8, 'base_rate' => 950]);
        $plan = $this->ratePlan($listing);
        $morning = $this->slot($unit, '09:00', 'Morning', 180);
        $sunset = $this->slot($unit, '17:00', 'Sunset', 180);

        InventoryWriteGuard::allow(fn () => RatePlanDay::create([
            'rate_plan_id' => $plan->id,
            'bookable_unit_id' => $unit->id,
            'slot_id' => $sunset->id,
            'date' => '2026-09-10',
            'rate' => 1400,
        ]));

        $this->book($listing, $unit, $morning, seats: 3);

        $cell = app(OccupancyGrid::class)->build($listing, $this->date, 7, $plan)->rows[0]->cells[0];

        $this->assertTrue($cell->isDepartureDay());
        $this->assertSame(2, $cell->departures);
        $this->assertSame(16, $cell->capacity);
        $this->assertSame(3, $cell->unitsSold);
        $this->assertSame(13, $cell->unitsFree);
        // The cheapest seat of the day: a "from" price is the only honest one
        // when the morning and the sunset drive cost different money.
        $this->assertSame(950.0, $cell->rate);

        // A day with no departure sold is still the day's full seat count.
        $this->assertSame(16, $cell = app(OccupancyGrid::class)->build($listing, $this->date, 7, $plan)->rows[0]->cells[1]->unitsFree);
    }

    public function test_a_day_costs_a_fixed_number_of_queries_whatever_the_timetable(): void
    {
        $small = Listing::factory()->create();
        $unit = $this->unit($small);
        // Booked, because an eager load over an empty result set is one query
        // fewer and the comparison below would be measuring that instead.
        $this->book($small, $unit, $this->slot($unit, '09:00', 'Morning', 60), seats: 1);

        $large = Listing::factory()->create();

        for ($hour = 6; $hour < 18; $hour++) {
            $each = $this->unit($large, ['name' => 'Tour '.$hour]);
            $this->slot($each, str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00', 'Departure '.$hour, 60);
            $this->book($large, $each, BookingSlot::query()->where('bookable_unit_id', $each->id)->firstOrFail(), seats: 1);
        }

        $forOne = $this->countQueries(fn () => $this->day($small));
        $forTwelve = $this->countQueries(fn () => $this->day($large));

        $this->assertSame($forOne, $forTwelve, 'A day must not cost a query per departure.');
        $this->assertLessThanOrEqual(10, $forTwelve);
    }

    private function day(Listing $listing, ?RatePlan $plan = null, ?int $resolution = null): DayGridData
    {
        $day = app(DayGrid::class)->build($listing, $this->date, $plan, $resolution);

        $this->assertNotNull($day);

        return $day;
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

    private function slot(BookableUnit $unit, string $time, string $label, int $minutes): BookingSlot
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
    private function unit(Listing $listing, array $attributes = []): BookableUnit
    {
        return BookableUnit::factory()->create(array_merge([
            'listing_id' => $listing->id,
            'name' => 'Quad tour',
            'total_units' => 8,
            'base_rate' => 950,
            'currency' => 'NAD',
        ], $attributes));
    }

    private function book(Listing $listing, BookableUnit $unit, BookingSlot $slot, int $seats, string $guest = 'Guest'): void
    {
        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($unit, $seats, $this->date, $this->date->copy()->addDay(), slot: $slot)],
            guestName: $guest,
            source: ReservationSource::WalkIn,
            notify: false,
        ));
    }
}
