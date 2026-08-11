<?php

namespace Tests\Feature\Booking;

use App\Enums\PricingStrategy;
use App\Enums\ReservationSource;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Services\Booking\RoomAvailability;
use App\Services\Booking\RoomCapacity;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Inventory\ManualBooking;
use App\Services\Pricing\GuestCategoryDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Capacity — the bug BOOKING_SYSTEM.md recorded: a room for 2 + 2 took a
 * booking for 2 + 3 without an error or a warning.
 *
 * The diagnosis was that nothing *accepted* a capacity rule; one screen merely
 * declined to offer you the room. So this file asserts the three different
 * answers one question now gets, and that they are different on purpose:
 *
 * - the picker does not offer a room the party does not fit;
 * - the traveller-facing request refuses one;
 * - the desk is warned, and its answer is kept.
 *
 * The third is not a softer version of the second. At a desk a cot goes in the
 * room, and a receptionist told "no" by software they cannot argue with writes
 * the booking on paper — after which the property's own system does not know
 * about the guest at all.
 */
class RoomCapacityTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $checkIn;

    private Carbon $checkOut;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));
        $this->checkIn = Carbon::parse('2026-09-10')->startOfDay();
        $this->checkOut = Carbon::parse('2026-09-12')->startOfDay();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_child_may_take_a_spare_adult_slot_but_never_the_other_way_round(): void
    {
        $room = $this->room(['max_adults' => 3, 'max_children' => 0]);

        $this->assertTrue(RoomCapacity::fits($room, adults: 2, children: 1));
        $this->assertTrue(RoomCapacity::fits($room, adults: 3, children: 0));
        $this->assertFalse(RoomCapacity::fits($room, adults: 4, children: 0));

        // Three adults in a room for two, with a spare child bed, is still
        // three adults in a room for two.
        $small = $this->room(['max_adults' => 2, 'max_children' => 2]);

        $this->assertFalse(RoomCapacity::fits($small, adults: 3, children: 0));
        $this->assertTrue(RoomCapacity::fits($small, adults: 2, children: 2));
    }

    /** The bug as reported, at the arithmetic level. */
    public function test_the_reported_case_does_not_fit(): void
    {
        $room = $this->room(['name' => 'Standard Chalet', 'max_adults' => 2, 'max_children' => 2]);

        $this->assertFalse(RoomCapacity::fits($room, adults: 2, children: 3));
        $this->assertSame(
            'Standard Chalet sleeps 2 + 2, and this booking is 2 + 3.',
            RoomCapacity::explain([[$room, 1]], 2, 3),
        );
    }

    /**
     * Summed and not attributed. A stay holding two rooms under a per-room
     * tariff has its guest counts on the header and nothing saying who is
     * where; splitting them would be inventing an attribution.
     */
    public function test_capacity_is_asked_of_the_whole_booking(): void
    {
        $double = $this->room(['max_adults' => 2, 'max_children' => 0]);

        $this->assertTrue(RoomCapacity::fitsAcross([[$double, 2]], adults: 4, children: 0));
        $this->assertFalse(RoomCapacity::fitsAcross([[$double, 2]], adults: 5, children: 0));
        $this->assertTrue(RoomCapacity::fitsAcross([[$double, 1], [$double, 1]], adults: 4, children: 0));
    }

    /** A room type nobody has filled capacity in says nothing about capacity. */
    public function test_a_room_with_no_capacity_entered_refuses_nobody(): void
    {
        $unknown = $this->room(['max_adults' => 0, 'max_children' => 0]);

        $this->assertTrue(RoomCapacity::fits($unknown, adults: 9, children: 9));
    }

    public function test_the_picker_still_declines_to_offer_a_room_that_does_not_fit(): void
    {
        $listing = Listing::factory()->create();
        $this->room(['listing_id' => $listing->id, 'name' => 'Family Room', 'max_adults' => 2, 'max_children' => 2]);

        $offered = fn (int $adults, int $children) => RoomAvailability::bookableFor(
            $listing->id,
            $this->checkIn,
            $this->checkOut,
            $adults,
            $children,
        )->count();

        $this->assertSame(1, $offered(2, 2));
        $this->assertSame(0, $offered(2, 3));
    }

    /**
     * The desk half. Warned, not refused — and the booking is only saved once
     * the desk has said what it is doing about it.
     */
    public function test_the_desk_is_warned_and_the_answer_is_kept_on_the_stay(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room([
            'listing_id' => $listing->id,
            'name' => 'Standard Chalet',
            'max_adults' => 2,
            'max_children' => 2,
            'total_units' => 3,
            'rate_per_night' => 1200,
        ]);

        $manual = app(ManualBooking::class);
        $lines = [['bookable_unit_id' => $room->id, 'quantity' => 1]];

        $fits = $manual->preview($listing, $this->checkIn, $this->checkOut, $lines, adults: 2, children: 2);

        $this->assertFalse($fits->hasWarnings());

        $tight = $manual->preview($listing, $this->checkIn, $this->checkOut, $lines, adults: 2, children: 3);

        $this->assertSame(
            ['Standard Chalet sleeps 2 + 2, and this booking is 2 + 3.'],
            $tight->warnings,
        );
        // A warning is not a problem: the desk may still take it.
        $this->assertTrue($tight->isBookable());
        $this->assertSame([], $tight->problems);

        $reservation = $manual->place(
            listing: $listing,
            checkIn: $this->checkIn,
            checkOut: $this->checkOut,
            lines: $lines,
            guestName: 'Familie Braun',
            source: ReservationSource::WalkIn,
            adults: 2,
            children: 3,
            overCapacityNote: 'Cot in the chalet',
        );

        $this->assertSame('Cot in the chalet', $reservation->refresh()->over_capacity_note);
    }

    /** Nearly every booking fits, and carries nothing. */
    public function test_a_booking_that_fits_records_nothing(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room(['listing_id' => $listing->id, 'max_adults' => 2, 'max_children' => 2, 'total_units' => 2]);

        $reservation = app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, $this->checkIn, $this->checkOut)],
            guestName: 'Fits Fine',
            source: ReservationSource::WalkIn,
            adults: 2,
            children: 1,
            notify: false,
        ));

        $this->assertNull($reservation->over_capacity_note);
    }

    /**
     * Where a plan prices by guests the room lines carry their own people and
     * the pricer refuses a line it cannot price — a hard refusal, because
     * there is no price to compute. One voice on one question.
     */
    public function test_a_per_guest_plan_refuses_rather_than_warns(): void
    {
        $listing = Listing::factory()->create();
        $room = $this->room(['listing_id' => $listing->id, 'max_adults' => 2, 'max_children' => 0, 'total_units' => 2]);

        RatePlan::create([
            'listing_id' => $listing->id,
            'name' => 'Per person sharing',
            'code' => 'PPS',
            'pricing_strategy' => PricingStrategy::PerPersonSharing,
            'is_default' => true,
        ]);

        $categories = GuestCategoryDefaults::ensureFor($listing);
        $adult = $categories->firstWhere('code', 'adult');

        $preview = app(ManualBooking::class)->preview(
            $listing,
            $this->checkIn,
            $this->checkOut,
            [[
                'bookable_unit_id' => $room->id,
                'guests' => [['guest_category_id' => $adult?->id, 'count' => 4]],
            ]],
            adults: 4,
        );

        $this->assertFalse($preview->isBookable());
        $this->assertNotSame([], $preview->problems);
        $this->assertSame([], $preview->warnings, 'One question, one voice.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function room(array $attributes = []): BookableUnit
    {
        return BookableUnit::factory()->create(array_merge([
            'total_units' => 2,
            'rate_per_night' => 1200,
            'currency' => 'NAD',
        ], $attributes));
    }
}
