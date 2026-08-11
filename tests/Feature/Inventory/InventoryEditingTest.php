<?php

namespace Tests\Feature\Inventory;

use App\Enums\BlockReason;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Exceptions\Inventory\InventoryUnavailableException;
use App\Models\BookableUnit;
use App\Models\BookableUnitCalendarDay;
use App\Models\InventoryBlock;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\RatePlanDay;
use App\Models\Reservation;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DTOs\BlockRequest;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * What slice 3 added to the write path: a price the lodge can override
 * without losing what the calendar said, blocks that can be changed rather
 * than only created and released, a bulk calendar write that understands
 * "weekends only", and the one destructive operation in the inventory domain
 * — which refuses to touch anything but a demo tenant.
 */
class InventoryEditingTest extends TestCase
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

    private function listing(?Partner $partner = null): Listing
    {
        return Listing::factory()->create([
            'is_published' => true,
            'partner_id' => $partner?->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function room(Listing $listing, array $attributes = []): BookableUnit
    {
        return BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 3,
            'rate_per_night' => 1000,
            'currency' => 'NAD',
            ...$attributes,
        ]);
    }

    public function test_a_stay_across_a_season_boundary_prices_per_night_and_records_an_override(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        // Two seasons meeting mid-stay. The point of writing a season onto the
        // dates is that this needs no special case when it is priced.
        $this->writer->setCalendar($room, now()->parse('2026-09-01'), now()->parse('2026-09-02'), ['rate' => 800]);
        $this->writer->setCalendar($room, now()->parse('2026-09-03'), now()->parse('2026-09-05'), ['rate' => 1500]);

        $reservation = $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, now()->parse('2026-09-01'), now()->parse('2026-09-05'))],
            guestName: 'Season Crosser',
            source: ReservationSource::Telephone,
            totalOverride: 4000.0,
            overrideReason: 'Repeat guest rate agreed on the phone',
        ));

        // 800 + 800 + 1500 + 1500 — the calendar's own arithmetic, kept.
        $this->assertSame(4600.0, $reservation->quoted_amount);
        $this->assertSame(4000.0, $reservation->total_amount);
        $this->assertTrue($reservation->priceWasOverridden());
        $this->assertSame('Repeat guest rate agreed on the phone', $reservation->price_override_reason);
        $this->assertNotNull($reservation->price_overridden_at);

        // The line stays at the calendar price; the difference between the two
        // totals is the whole record of what was decided.
        $this->assertSame(4600.0, (float) $reservation->units->first()->total_amount);
    }

    public function test_a_booking_at_the_calendar_price_records_no_override(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $reservation = $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, now()->parse('2026-09-01'), now()->parse('2026-09-03'))],
            guestName: 'Rack Rate',
            source: ReservationSource::WalkIn,
            overrideReason: 'ignored, because nothing was overridden',
        ));

        $this->assertSame(2000.0, $reservation->total_amount);
        $this->assertSame(2000.0, $reservation->quoted_amount);
        $this->assertFalse($reservation->priceWasOverridden());
        $this->assertNull($reservation->price_override_reason);
        $this->assertNull($reservation->price_overridden_at);
    }

    public function test_a_booking_that_would_exceed_availability_is_refused(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['total_units' => 2]);

        $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 2, now()->parse('2026-09-10'), now()->parse('2026-09-12'))],
            guestName: 'Fills The House',
            source: ReservationSource::WalkIn,
        ));

        try {
            $this->writer->book(new BookingRequest(
                listing: $listing,
                lines: [new BookingLine($room, 1, now()->parse('2026-09-11'), now()->parse('2026-09-13'))],
                guestName: 'One Too Many',
                source: ReservationSource::Telephone,
            ));
            $this->fail('A booking beyond the units available should have been refused.');
        } catch (InventoryUnavailableException) {
            // expected
        }

        // Refused, not partially written: the whole reservation rolls back.
        $this->assertSame(1, Reservation::where('listing_id', $listing->id)->count());
    }

    public function test_editing_a_block_moves_the_units_it_holds(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['total_units' => 2]);

        $block = $this->writer->block(new BlockRequest(
            bookableUnit: $room,
            units: 1,
            firstNight: now()->parse('2026-10-01'),
            lastNight: now()->parse('2026-10-03'),
            reason: BlockReason::Maintenance,
        ));

        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-10-01')));

        $this->writer->updateBlock($block, new BlockRequest(
            bookableUnit: $room,
            units: 2,
            firstNight: now()->parse('2026-10-05'),
            lastNight: now()->parse('2026-10-06'),
            reason: BlockReason::OwnerUse,
            note: 'Moved a week later',
        ));

        // The old nights are back on sale, the new ones are off it.
        $this->assertSame(2, $this->calendar->unitsFree($room, now()->parse('2026-10-01')));
        $this->assertSame(0, $this->calendar->unitsFree($room, now()->parse('2026-10-05')));
        $this->assertSame(0, $this->calendar->unitsFree($room, now()->parse('2026-10-06')));

        $block->refresh();
        $this->assertSame(BlockReason::OwnerUse, $block->reason);
        $this->assertSame(2, $block->units);
    }

    public function test_a_block_edit_that_does_not_fit_leaves_the_original_block_intact(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['total_units' => 2]);

        $block = $this->writer->block(new BlockRequest(
            bookableUnit: $room,
            units: 1,
            firstNight: now()->parse('2026-11-01'),
            lastNight: now()->parse('2026-11-02'),
        ));

        // Someone else has the other unit on one of the nights the block is
        // being widened over.
        $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, now()->parse('2026-11-02'), now()->parse('2026-11-03'))],
            guestName: 'In The Way',
            source: ReservationSource::WalkIn,
        ));

        try {
            $this->writer->updateBlock($block, new BlockRequest(
                bookableUnit: $room,
                units: 2,
                firstNight: now()->parse('2026-11-01'),
                lastNight: now()->parse('2026-11-02'),
            ));
            $this->fail('A block that does not fit should have been refused.');
        } catch (InventoryUnavailableException) {
            // expected
        }

        $block->refresh();

        $this->assertSame(1, $block->units);
        $this->assertNull($block->released_at);
        // The rollback has to restore the counters too, not only the row.
        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-11-01')));
    }

    public function test_releasing_a_block_puts_the_units_back_and_a_released_block_cannot_be_edited(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['total_units' => 2]);

        $block = $this->writer->block(new BlockRequest(
            bookableUnit: $room,
            units: 2,
            firstNight: now()->parse('2026-12-01'),
            lastNight: now()->parse('2026-12-02'),
        ));

        $this->assertSame(0, $this->calendar->unitsFree($room, now()->parse('2026-12-01')));

        $this->writer->releaseBlock($block);

        $this->assertSame(2, $this->calendar->unitsFree($room, now()->parse('2026-12-01')));

        $this->expectException(InvalidArgumentException::class);
        $this->writer->updateBlock($block->refresh(), new BlockRequest(
            bookableUnit: $room,
            units: 1,
            firstNight: now()->parse('2026-12-01'),
            lastNight: now()->parse('2026-12-02'),
        ));
    }

    public function test_a_bulk_rate_edit_applies_to_exactly_the_intended_range(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $written = $this->writer->setCalendar(
            $room,
            now()->parse('2026-09-10'),
            now()->parse('2026-09-14'),
            ['rate' => 2200, 'min_stay' => 2],
        );

        $this->assertSame(5, $written);

        $this->assertSame(1000.0, $this->calendar->rateFor($room, now()->parse('2026-09-09')));
        $this->assertSame(2200.0, $this->calendar->rateFor($room, now()->parse('2026-09-10')));
        $this->assertSame(2200.0, $this->calendar->rateFor($room, now()->parse('2026-09-14')));
        $this->assertSame(1000.0, $this->calendar->rateFor($room, now()->parse('2026-09-15')));

        // Nothing outside the range gained a row at all — and rates land in the
        // rate plan, so the inventory calendar stays empty here.
        $this->assertSame(5, RatePlanDay::where('bookable_unit_id', $room->id)->count());
        $this->assertSame(0, BookableUnitCalendarDay::where('bookable_unit_id', $room->id)->count());
    }

    public function test_a_bulk_edit_can_be_restricted_to_certain_weekdays(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        // 2026-09-07 is a Monday, so this fortnight holds two Saturdays and
        // two Sundays.
        $written = $this->writer->setCalendar(
            $room,
            now()->parse('2026-09-07'),
            now()->parse('2026-09-20'),
            ['rate' => 1800],
            [6, 7],
        );

        $this->assertSame(4, $written);

        $this->assertSame(1800.0, $this->calendar->rateFor($room, now()->parse('2026-09-12'))); // Saturday
        $this->assertSame(1800.0, $this->calendar->rateFor($room, now()->parse('2026-09-13'))); // Sunday
        $this->assertSame(1000.0, $this->calendar->rateFor($room, now()->parse('2026-09-14'))); // Monday
    }

    public function test_a_bulk_edit_never_resets_what_a_booking_has_consumed(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 2, now()->parse('2026-09-10'), now()->parse('2026-09-12'))],
            guestName: 'Already Here',
            source: ReservationSource::Website,
        ));

        $this->writer->setCalendar($room, now()->parse('2026-09-01'), now()->parse('2026-09-30'), ['rate' => 2500]);

        $this->assertSame(1, $this->calendar->unitsFree($room, now()->parse('2026-09-10')));
        $this->assertSame(2500.0, $this->calendar->rateFor($room, now()->parse('2026-09-10')));
    }

    public function test_an_invalid_weekday_is_refused(): void
    {
        $room = $this->room($this->listing());

        $this->expectException(InvalidArgumentException::class);

        $this->writer->setCalendar(
            $room,
            now()->parse('2026-09-01'),
            now()->parse('2026-09-07'),
            ['rate' => 1200],
            [0],
        );
    }

    public function test_purging_refuses_a_property_that_is_not_a_demo_tenant(): void
    {
        $partner = Partner::create(['name' => 'A Real Lodge Group', 'email' => 'owner@example.com']);
        $listing = $this->listing($partner);
        $room = $this->room($listing);

        $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, now()->parse('2026-09-01'), now()->parse('2026-09-03'))],
            guestName: 'Real Guest',
            source: ReservationSource::Website,
        ));

        try {
            $this->writer->purgeProperty($listing);
            $this->fail('Purging a real property should have been refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(1, Reservation::where('listing_id', $listing->id)->count());
    }

    public function test_purging_a_demo_property_removes_its_whole_book(): void
    {
        $partner = Partner::create(['name' => 'Demo Lodge', 'is_demo' => true]);
        $listing = $this->listing($partner);
        $room = $this->room($listing);

        $this->writer->setCalendar($room, now()->parse('2026-09-01'), now()->parse('2026-09-10'), ['rate' => 900]);

        $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, now()->parse('2026-09-01'), now()->parse('2026-09-03'))],
            guestName: 'Demo Guest',
            source: ReservationSource::WalkIn,
        ));

        $this->writer->block(new BlockRequest(
            bookableUnit: $room,
            units: 1,
            firstNight: now()->parse('2026-09-05'),
            lastNight: now()->parse('2026-09-06'),
        ));

        $removed = $this->writer->purgeProperty($listing);

        $this->assertSame(1, $removed['reservations']);
        $this->assertSame(1, $removed['blocks']);
        $this->assertSame(0, Reservation::where('listing_id', $listing->id)->count());
        $this->assertSame(0, InventoryBlock::where('bookable_unit_id', $room->id)->count());
        $this->assertSame(0, BookableUnitCalendarDay::where('bookable_unit_id', $room->id)->count());

        // And the room type survives, because purging is about the book, not
        // about the property's shape.
        $this->assertTrue(BookableUnit::whereKey($room->id)->exists());
    }

    public function test_the_stay_lifecycle_rejects_a_transition_that_skips_the_guest_arriving(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $reservation = $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, now()->parse('2026-09-01'), now()->parse('2026-09-03'))],
            guestName: 'Lifecycle',
            source: ReservationSource::WalkIn,
            status: StayStatus::Confirmed,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->writer->transition($reservation, StayStatus::CheckedOut);
    }
}
