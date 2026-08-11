<?php

namespace Tests\Feature\Inventory;

use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\RoomType;
use App\Models\RoomTypeCalendarDay;
use App\Services\Inventory\InventoryWriteGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A departure is a row on the calendar beside the date, not instead of it.
 *
 * What is worth pinning here is the uniqueness rule, because it is the whole
 * design in one constraint: one row per unit per night for anything sold by
 * the day, one row per unit per date per departure for anything sold by the
 * hour, and never a second counter for the same rooms.
 */
class BookingSlotTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $listing = Listing::factory()->create();
        $this->unit = RoomType::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 8,
            'currency' => 'NAD',
        ]);
    }

    public function test_a_property_sold_by_the_night_has_no_departures(): void
    {
        $this->assertFalse($this->unit->hasSlots());
        $this->assertCount(0, BookingSlot::forUnit($this->unit));
    }

    public function test_two_departures_on_one_day_are_two_pools_of_seats(): void
    {
        $morning = $this->slot('09:00');
        $afternoon = $this->slot('14:00');

        $this->day($morning, sold: 8);
        $this->day($afternoon, sold: 0);

        // The morning is full and the afternoon is empty on the same date,
        // which is the thing a single row per day cannot express.
        $this->assertSame(8, $this->soldOn('2026-09-10', $morning));
        $this->assertSame(0, $this->soldOn('2026-09-10', $afternoon));
    }

    public function test_one_row_per_departure_per_day(): void
    {
        $morning = $this->slot('09:00');
        $this->day($morning);

        $this->expectException(QueryException::class);

        $this->day($morning);
    }

    public function test_one_row_per_night_for_anything_sold_by_the_day(): void
    {
        // The rule that was already there and has to keep holding: SQL treats
        // NULLs as distinct, so a single unique index over the three columns
        // would quietly let a lodge keep two counters for the same night.
        $this->day(null);

        $this->expectException(QueryException::class);

        $this->day(null);
    }

    public function test_a_departure_that_runs_past_midnight_is_a_duration_not_a_second_date(): void
    {
        $sunset = $this->slot('22:00', minutes: 180);

        $this->assertSame(
            '2026-09-11 01:00',
            $sunset->endsOn(Carbon::parse('2026-09-10'))->format('Y-m-d H:i'),
        );
    }

    public function test_a_departure_is_labelled_by_its_name_or_its_time(): void
    {
        $this->assertSame('09:00', $this->slot('09:00')->label());
        $this->assertSame('Sunset drive', $this->slot('17:30', label: 'Sunset drive')->label());
    }

    private function slot(string $time, ?string $label = null, int $minutes = 180): BookingSlot
    {
        return BookingSlot::create([
            'room_type_id' => $this->unit->id,
            'label' => $label,
            'starts_at' => $time,
            'duration_minutes' => $minutes,
        ]);
    }

    /**
     * Written straight to the table on purpose. This test is about the
     * constraint the database enforces, not about the writer — and the writer
     * cannot express a departure yet, which is the next slice.
     */
    private function day(?BookingSlot $slot, int $sold = 0): RoomTypeCalendarDay
    {
        return InventoryWriteGuard::allow(fn () => RoomTypeCalendarDay::create([
            'room_type_id' => $this->unit->id,
            'slot_id' => $slot?->id,
            'date' => '2026-09-10',
            'units_sold' => $sold,
        ]));
    }

    private function soldOn(string $date, BookingSlot $slot): int
    {
        return (int) RoomTypeCalendarDay::query()
            ->where('room_type_id', $this->unit->id)
            ->where('slot_id', $slot->id)
            ->whereDate('date', $date)
            ->value('units_sold');
    }
}
