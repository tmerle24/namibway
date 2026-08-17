<?php

namespace Tests\Feature\Inventory;

use App\Enums\BlockReason;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Exceptions\Inventory\InventoryUnavailableException;
use App\Exceptions\Inventory\StayRuleViolationException;
use App\Models\BookableUnit;
use App\Models\BookableUnitCalendarDay;
use App\Models\Listing;
use App\Models\Reservation;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DTOs\BlockRequest;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rules the ARI calendar has to keep: units run out, seasons price per
 * night, restrictions refuse a stay, blocks take rooms off sale, and a night
 * nobody has touched still knows what it costs.
 */
class InventoryWriterTest extends TestCase
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
        return Listing::factory()->create(['is_published' => true]);
    }

    /** @param array<string, mixed> $attributes */
    private function room(Listing $listing, array $attributes = []): BookableUnit
    {
        return BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 2,
            'base_rate' => 1000,
            'currency' => 'NAD',
            ...$attributes,
        ]);
    }

    private function book(Listing $listing, BookableUnit $room, string $checkIn, string $checkOut, int $quantity = 1): Reservation
    {
        return $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, $quantity, now()->parse($checkIn), now()->parse($checkOut))],
            guestName: 'Test Guest',
            source: ReservationSource::WalkIn,
        ));
    }

    public function test_overlapping_stays_consume_a_bookable_unit_with_several_units(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['total_units' => 3]);

        // Three stays that all cover the night of the 11th, staggered so that
        // no two share the same arrival — the case a single-unit model cannot
        // tell apart from one long booking.
        $this->book($listing, $room, '2026-09-10', '2026-09-13'); // A
        $this->book($listing, $room, '2026-09-11', '2026-09-12'); // B
        $this->book($listing, $room, '2026-09-09', '2026-09-14'); // C

        // 9th: C only. 10th: A + C. 11th: all three. 12th: A + C. 13th: C only.
        $this->assertSame(2, $this->calendar->unitsFree($room, now()->parse('2026-09-09')));
        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-09-10')));
        $this->assertSame(0, $this->calendar->unitsFree($room, now()->parse('2026-09-11')));
        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-09-12')));
        $this->assertSame(2, $this->calendar->unitsFree($room, now()->parse('2026-09-13')));

        // C checks out on the 14th, so the 14th is nobody's night: intervals
        // are half-open and a departure day is not a night.
        $this->assertSame(3, $this->calendar->unitsFree($room, now()->parse('2026-09-14')));

        $this->expectException(InventoryUnavailableException::class);
        $this->book($listing, $room, '2026-09-11', '2026-09-12');
    }

    public function test_a_stay_crossing_a_season_boundary_is_priced_per_night(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['base_rate' => 1000]);

        // Low season through the 11th, high season from the 12th — a season is
        // a range written onto dates, not an entity resolved at read time.
        $this->writer->setCalendar($room, now()->parse('2026-09-01'), now()->parse('2026-09-11'), ['rate' => 900]);
        $this->writer->setCalendar($room, now()->parse('2026-09-12'), now()->parse('2026-09-30'), ['rate' => 2500]);

        $reservation = $this->book($listing, $room, '2026-09-10', '2026-09-14');

        // Nights: 10th and 11th at 900, 12th and 13th at 2500. The 14th is the
        // departure day and is not charged.
        $this->assertSame(6800.0, $reservation->total_amount);

        $nights = $reservation->units->first()?->nights()->orderBy('date')->get();
        $this->assertNotNull($nights);
        $this->assertSame([900.0, 900.0, 2500.0, 2500.0], $nights->pluck('rate')->map(fn ($r) => (float) $r)->all());
    }

    public function test_a_minimum_stay_refuses_a_shorter_booking(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $this->writer->setCalendar($room, now()->parse('2026-12-24'), now()->parse('2026-12-26'), ['min_stay' => 3]);

        try {
            $this->book($listing, $room, '2026-12-24', '2026-12-26');
            $this->fail('A two-night stay should not satisfy a three-night minimum.');
        } catch (StayRuleViolationException $e) {
            $this->assertSame(StayRuleViolationException::MIN_STAY, $e->rule);
        }

        // Three nights is fine, and nothing was consumed by the refused attempt.
        $reservation = $this->book($listing, $room, '2026-12-24', '2026-12-27');
        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-12-24')));
        $this->assertNotNull($reservation->id);
    }

    public function test_closed_to_arrival_refuses_an_arrival_but_not_a_stay_passing_through(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $this->writer->setCalendar($room, now()->parse('2026-10-05'), now()->parse('2026-10-05'), ['closed_to_arrival' => true]);

        try {
            $this->book($listing, $room, '2026-10-05', '2026-10-08');
            $this->fail('Arriving on a closed-to-arrival night should be refused.');
        } catch (StayRuleViolationException $e) {
            $this->assertSame(StayRuleViolationException::CLOSED_TO_ARRIVAL, $e->rule);
        }

        // The restriction is about arriving, not about occupying: a stay that
        // starts earlier and merely covers that night is allowed.
        $reservation = $this->book($listing, $room, '2026-10-04', '2026-10-08');
        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-10-05')));
        $this->assertNotNull($reservation->id);
    }

    public function test_closed_to_departure_refuses_a_check_out_on_that_day(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $this->writer->setCalendar($room, now()->parse('2026-10-08'), now()->parse('2026-10-08'), ['closed_to_departure' => true]);

        try {
            $this->book($listing, $room, '2026-10-05', '2026-10-08');
            $this->fail('Departing on a closed-to-departure day should be refused.');
        } catch (StayRuleViolationException $e) {
            $this->assertSame(StayRuleViolationException::CLOSED_TO_DEPARTURE, $e->rule);
        }
    }

    public function test_a_block_removes_units_from_availability_and_stays_distinguishable(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['total_units' => 2]);

        $block = $this->writer->block(new BlockRequest(
            bookableUnit: $room,
            units: 1,
            firstNight: now()->parse('2026-11-02'),
            lastNight: now()->parse('2026-11-04'),
            reason: BlockReason::Maintenance,
            note: 'Roof repair',
        ));

        // first_night and last_night are inclusive, so the 4th is blocked and
        // the 5th is not.
        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-11-02')));
        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-11-04')));
        $this->assertSame(2, $this->calendar->unitsFree($room, now()->parse('2026-11-05')));

        // A block is not a guest booking, and reading the day back says so.
        $day = BookableUnitCalendarDay::where('bookable_unit_id', $room->id)
            ->whereDate('date', '2026-11-02')
            ->firstOrFail();
        $this->assertSame(1, $day->units_blocked);
        $this->assertSame(0, $day->units_sold);

        // One unit is left, so one booking fits and the second does not.
        $this->book($listing, $room, '2026-11-02', '2026-11-03');
        $this->assertSame(0, $this->calendar->unitsFree($room, now()->parse('2026-11-02')));

        $this->writer->releaseBlock($block);
        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-11-02')));
    }

    public function test_a_night_with_no_calendar_row_falls_back_to_the_bookable_unit_defaults(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['total_units' => 4, 'base_rate' => 1750]);

        $this->assertSame(0, BookableUnitCalendarDay::where('bookable_unit_id', $room->id)->count());

        $this->assertSame(4, $this->calendar->unitsFree($room, now()->parse('2027-01-15')));
        $this->assertSame(1750.0, $this->calendar->rateFor($room, now()->parse('2027-01-15')));

        // And the fallback is live, not copied: raising the room type's units
        // must show up on every night nobody explicitly overrode.
        $room->update(['total_units' => 6]);
        $this->assertSame(6, $this->calendar->unitsFree($room->fresh(), now()->parse('2027-01-15')));
    }

    public function test_an_override_wins_over_the_bookable_unit_default(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['total_units' => 4, 'base_rate' => 1000]);

        $this->writer->setCalendar($room, now()->parse('2027-02-01'), now()->parse('2027-02-01'), [
            'units_total' => 1,
            'rate' => 3000,
        ]);

        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2027-02-01')));
        $this->assertSame(3000.0, $this->calendar->rateFor($room, now()->parse('2027-02-01')));

        $this->book($listing, $room, '2027-02-01', '2027-02-02');

        $this->expectException(InventoryUnavailableException::class);
        $this->book($listing, $room, '2027-02-01', '2027-02-02');
    }

    public function test_cancelling_gives_the_units_back_and_is_idempotent(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['total_units' => 1]);

        $reservation = $this->book($listing, $room, '2026-09-20', '2026-09-22');
        $this->assertSame(0, $this->calendar->unitsFree($room, now()->parse('2026-09-20')));

        $this->writer->cancel($reservation, 'Guest changed plans', late: false);
        $this->assertSame(StayStatus::Cancelled, $reservation->fresh()?->status);
        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-09-20')));

        // A partner clicking the link twice must not hand the room out twice.
        $this->writer->cancel($reservation->fresh(), 'Double click', late: false);
        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-09-20')));
    }

    public function test_one_reservation_can_hold_several_bookable_units_with_quantities(): void
    {
        $listing = $this->listing();
        $standard = $this->room($listing, ['total_units' => 4, 'base_rate' => 1000]);
        $family = $this->room($listing, ['total_units' => 2, 'base_rate' => 2000]);

        $reservation = $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [
                new BookingLine($standard, 2, now()->parse('2026-09-01'), now()->parse('2026-09-03')),
                new BookingLine($family, 1, now()->parse('2026-09-01'), now()->parse('2026-09-03')),
            ],
            guestName: 'Family of five',
            source: ReservationSource::Telephone,
            adults: 4,
            children: 1,
        ));

        // 2 standard x 2 nights x 1000 + 1 family x 2 nights x 2000
        $this->assertSame(8000.0, $reservation->total_amount);
        $this->assertCount(2, $reservation->units);
        $this->assertSame(2, $this->calendar->unitsFree($standard, now()->parse('2026-09-01')));
        $this->assertSame(1, $this->calendar->unitsFree($family, now()->parse('2026-09-01')));
    }

    public function test_the_stay_lifecycle_moves_forward_and_refuses_impossible_jumps(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $reservation = $this->book($listing, $room, '2026-09-01', '2026-09-03');

        $this->writer->transition($reservation, StayStatus::DueIn);
        $this->writer->transition($reservation, StayStatus::InHouse);
        $this->writer->transition($reservation, StayStatus::CheckedOut);
        $this->assertSame(StayStatus::CheckedOut, $reservation->fresh()?->status);

        $this->expectException(\InvalidArgumentException::class);
        $this->writer->transition($reservation, StayStatus::InHouse);
    }

    public function test_cancelling_is_not_reachable_as_a_plain_status_change(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);
        $reservation = $this->book($listing, $room, '2026-09-01', '2026-09-03');

        // Going through transition() would leave the units consumed forever.
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->transition($reservation, StayStatus::Cancelled);
    }

    public function test_set_calendar_cannot_be_used_to_move_the_counters(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $this->expectException(\InvalidArgumentException::class);
        $this->writer->setCalendar($room, now()->parse('2026-09-01'), now()->parse('2026-09-02'), [
            'units_sold' => 0,
        ]);
    }
}
