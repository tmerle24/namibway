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
 * How the two availability readers relate — rewritten 2026-08-12, when they
 * were joined.
 *
 * This file used to assert the opposite: that a stay written through
 * InventoryWriter stayed invisible to the traveller-facing picker. That was
 * the right guard at the time — it existed so the merge would be a decision
 * somebody made rather than a coupling that drifted in, on a repository where
 * a green push to main ships straight to production. It did its job: joining
 * them turned this test red, and the change had to be argued for.
 *
 * The rule now: **the smaller of the two counts wins.** A stay on the calendar
 * takes a room off the trip plan, and a request in flight — which holds no ARI
 * inventory, by design — is still subtracted on top.
 */
class RoomAvailabilityUnchangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_booking_the_lodge_took_is_no_longer_offered_to_a_traveller(): void
    {
        $listing = Listing::factory()->create();
        $room = RoomType::factory()->create([
            'listing_id' => $listing->id,
            'code' => 'standard',
            'total_units' => 2,
            'max_adults' => 2,
        ]);

        $this->assertSame(2, RoomAvailability::unitsLeft($listing->id, $room, now()->parse('2026-09-01'), now()->parse('2026-09-03')));

        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 2, now()->parse('2026-09-01'), now()->parse('2026-09-03'))],
            guestName: 'Lodge-side booking',
            source: ReservationSource::WalkIn,
            notify: false,
        ));

        $this->assertSame(0, app(AvailabilityCalendar::class)->unitsFree($room, now()->parse('2026-09-01')));

        // Before the merge this still read 2, and the trip plan went on
        // offering both rooms.
        $this->assertSame(
            0,
            RoomAvailability::unitsLeft($listing->id, $room, now()->parse('2026-09-01'), now()->parse('2026-09-03')),
        );
    }

    /**
     * The half that is deliberately not merged: holding real rooms for every
     * question is the flooding problem the whole booking mechanic exists to
     * prevent, so a request in flight consumes no ARI inventory. It is counted
     * separately instead — which is why the traveller-facing number is the
     * smaller of the two and not the calendar's alone.
     */
    public function test_a_request_in_flight_holds_no_calendar_inventory_but_is_still_counted(): void
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

    /** Both readers at once: one room sold at the desk, one asked about online. */
    public function test_the_smaller_of_the_two_counts_wins(): void
    {
        $listing = Listing::factory()->create();
        $room = RoomType::factory()->create([
            'listing_id' => $listing->id,
            'code' => 'standard',
            'total_units' => 3,
            'max_adults' => 2,
        ]);

        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, now()->parse('2026-09-01'), now()->parse('2026-09-03'))],
            guestName: 'Lodge-side booking',
            source: ReservationSource::WalkIn,
            notify: false,
        ));

        Inquiry::create([
            'listing_id' => $listing->id,
            'name' => 'Traveller',
            'email' => 'traveller@example.com',
            'room_type_code' => 'standard',
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-03',
            'adults' => 2,
            'children' => 0,
            'status' => InquiryStatus::OnRequest,
        ]);

        // Two free on the calendar, two left after the request — and the
        // answer is two rather than one, because the same room is not
        // subtracted twice for being counted by both readers.
        $this->assertSame(2, RoomAvailability::unitsLeft($listing->id, $room, now()->parse('2026-09-01'), now()->parse('2026-09-03')));
    }
}
