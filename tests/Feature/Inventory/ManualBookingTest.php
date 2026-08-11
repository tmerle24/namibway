<?php

namespace Tests\Feature\Inventory;

use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Models\Listing;
use App\Models\RoomType;
use App\Services\Inventory\InventoryWriter;
use App\Services\Inventory\ManualBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The walk-in path: what a half-filled form is worth, and what a refusal
 * actually says.
 *
 * The refusal wording is tested rather than only the boolean, because it is
 * the whole point of checking before the writer does. "Sold out" ends a
 * conversation with a guest standing at the counter; a room type and a date
 * lets whoever is at the desk offer something else.
 */
class ManualBookingTest extends TestCase
{
    use RefreshDatabase;

    private ManualBooking $manual;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00'));
        $this->manual = app(ManualBooking::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function listing(): Listing
    {
        return Listing::factory()->create(['type' => ListingType::Accommodation]);
    }

    /** @param array<string, mixed> $attributes */
    private function room(Listing $listing, array $attributes = []): RoomType
    {
        return RoomType::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 2,
            'rate_per_night' => 1000,
            'currency' => 'NAD',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    public function test_a_booking_beyond_availability_is_refused_by_room_type_and_by_night(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['name' => 'Standard Chalet', 'total_units' => 2]);

        // One unit already gone on the middle night only.
        $this->manual->place(
            $listing,
            Carbon::parse('2026-09-11'),
            Carbon::parse('2026-09-12'),
            [['room_type_id' => $room->id, 'quantity' => 2]],
            'Already Here',
            ReservationSource::Website,
        );

        $preview = $this->manual->preview(
            $listing,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-13'),
            [['room_type_id' => $room->id, 'quantity' => 1]],
        );

        $this->assertFalse($preview->isBookable());
        $this->assertCount(1, $preview->problems);
        $this->assertStringContainsString('Standard Chalet', $preview->problems[0]);
        $this->assertStringContainsString('11 September', $preview->problems[0]);
    }

    public function test_a_bookable_stay_is_priced_per_night_across_a_season_boundary(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);
        $writer = app(InventoryWriter::class);

        $writer->setCalendar($room, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'), ['rate' => 900]);
        $writer->setCalendar($room, Carbon::parse('2026-09-12'), Carbon::parse('2026-09-14'), ['rate' => 1700]);

        $preview = $this->manual->preview(
            $listing,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-14'),
            [['room_type_id' => $room->id, 'quantity' => 1]],
        );

        $this->assertTrue($preview->isBookable());
        $this->assertSame(4, $preview->nights);
        $this->assertSame(5200.0, $preview->total); // 900 + 900 + 1700 + 1700
        $this->assertSame('NAD', $preview->currency);
    }

    public function test_placing_a_stay_records_an_override_against_the_calendar_price(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $reservation = $this->manual->place(
            $listing,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-12'),
            [['room_type_id' => $room->id, 'quantity' => 1]],
            'Repeat Guest',
            ReservationSource::Telephone,
            totalOverride: 1500.0,
            overrideReason: 'Agreed on the phone',
        );

        $this->assertSame(2000.0, $reservation->quoted_amount);
        $this->assertSame(1500.0, $reservation->total_amount);
        $this->assertTrue($reservation->priceWasOverridden());
        $this->assertSame('Agreed on the phone', $reservation->price_override_reason);

        // A stay somebody typed in has already been agreed to.
        $this->assertSame(StayStatus::Confirmed, $reservation->status);
        $this->assertSame(ReservationSource::Telephone, $reservation->source);
    }

    public function test_two_rows_of_the_same_room_type_are_one_line_of_the_right_size(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['total_units' => 5]);

        $reservation = $this->manual->place(
            $listing,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-11'),
            [
                ['room_type_id' => $room->id, 'quantity' => 2],
                ['room_type_id' => $room->id, 'quantity' => 1],
            ],
            'Group Of Three Rooms',
            ReservationSource::Email,
        );

        $this->assertCount(1, $reservation->units);
        $this->assertSame(3, $reservation->units->first()->quantity);
        $this->assertSame(3000.0, $reservation->total_amount);
    }

    public function test_a_room_type_belonging_to_another_property_is_ignored(): void
    {
        $listing = $this->listing();
        $this->room($listing);

        $foreign = $this->room($this->listing(), ['name' => 'Somebody Else’s Suite']);

        $preview = $this->manual->preview(
            $listing,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-12'),
            [['room_type_id' => $foreign->id, 'quantity' => 1]],
        );

        $this->assertFalse($preview->isBookable());
        $this->assertSame(['Choose at least one room type.'], $preview->problems);
    }

    public function test_a_restriction_is_reported_in_words_rather_than_as_a_failure(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing, ['name' => 'Luxury Tent']);

        app(InventoryWriter::class)->setCalendar(
            $room,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-10'),
            ['min_stay' => 3],
        );

        $preview = $this->manual->preview(
            $listing,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-11'),
            [['room_type_id' => $room->id, 'quantity' => 1]],
        );

        $this->assertFalse($preview->isBookable());
        $this->assertStringContainsString('Luxury Tent', $preview->problems[0]);
    }

    public function test_an_incomplete_form_says_what_is_missing_rather_than_throwing(): void
    {
        $listing = $this->listing();
        $room = $this->room($listing);

        $noDates = $this->manual->preview($listing, null, null, [['room_type_id' => $room->id, 'quantity' => 1]]);
        $this->assertSame(['Choose an arrival and a departure date.'], $noDates->problems);

        $backwards = $this->manual->preview(
            $listing,
            Carbon::parse('2026-09-12'),
            Carbon::parse('2026-09-10'),
            [['room_type_id' => $room->id, 'quantity' => 1]],
        );
        $this->assertSame(['The departure date has to be after the arrival date.'], $backwards->problems);

        $noRooms = $this->manual->preview(
            $listing,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-12'),
            [['room_type_id' => null, 'quantity' => 0]],
        );
        $this->assertSame(['Choose at least one room type.'], $noRooms->problems);
    }
}
