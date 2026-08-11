<?php

namespace Tests\Feature\Inventory;

use App\Enums\InquiryStatus;
use App\Enums\ReservationSource;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\RoomType;
use App\Services\Booking\RoomAvailability;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two availability readers are separate on purpose, and this test exists
 * to keep them that way.
 *
 * RoomAvailability answers the traveller-facing question from overlapping
 * Inquiry rows and drives the trip plan's room picker in production. The ARI
 * calendar answers the lodge-facing question. Pointing the traveller-facing
 * picker at the calendar is a later, deliberate step — until then, a change
 * that quietly couples them would change what a traveller sees, and a green
 * push to main ships straight to production.
 *
 * So: a stay written through InventoryWriter must be invisible to
 * RoomAvailability, and an inquiry must be invisible to the calendar.
 */
class RoomAvailabilityUnchangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_calendar_reservation_does_not_change_what_the_traveller_sees(): void
    {
        $listing = Listing::factory()->create();
        $room = RoomType::factory()->create([
            'listing_id' => $listing->id,
            'code' => 'standard',
            'total_units' => 2,
            'max_adults' => 2,
        ]);

        $before = RoomAvailability::unitsLeft($listing->id, $room, now()->parse('2026-09-01'), now()->parse('2026-09-03'));
        $this->assertSame(2, $before);

        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 2, now()->parse('2026-09-01'), now()->parse('2026-09-03'))],
            guestName: 'Lodge-side booking',
            source: ReservationSource::WalkIn,
        ));

        // The calendar knows the room is gone...
        $this->assertSame(0, app(AvailabilityCalendar::class)->unitsFree($room, now()->parse('2026-09-01')));

        // ...and the traveller-facing reader is deliberately unaffected.
        $this->assertSame(
            $before,
            RoomAvailability::unitsLeft($listing->id, $room, now()->parse('2026-09-01'), now()->parse('2026-09-03')),
            'RoomAvailability must keep deriving from inquiries until the merge is made deliberately.'
        );
    }

    public function test_an_inquiry_does_not_change_the_calendar(): void
    {
        $listing = Listing::factory()->create();
        $room = RoomType::factory()->create([
            'listing_id' => $listing->id,
            'code' => 'standard',
            'total_units' => 2,
        ]);

        Inquiry::create([
            'listing_id' => $listing->id,
            'name' => 'Traveller',
            'email' => 'traveller@example.com',
            'room_type_code' => 'standard',
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-03',
            'adults' => 2,
            'children' => 0,
            'status' => InquiryStatus::Confirmed,
        ]);

        $this->assertSame(1, RoomAvailability::unitsLeft($listing->id, $room, now()->parse('2026-09-01'), now()->parse('2026-09-03')));
        $this->assertSame(2, app(AvailabilityCalendar::class)->unitsFree($room, now()->parse('2026-09-01')));
    }
}
