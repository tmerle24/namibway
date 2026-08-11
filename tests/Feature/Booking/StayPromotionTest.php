<?php

namespace Tests\Feature\Booking;

use App\Enums\InquiryStatus;
use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Exceptions\Booking\StayNotPromotableException;
use App\Mail\GuestStayConfirmed;
use App\Mail\StayPromotionFailed;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Booking\InquiryDecisionService;
use App\Services\Booking\StayPromoter;
use App\Services\Inventory\AvailabilityCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The bridge: a confirmed request becomes a stay on the calendar.
 *
 * Two properties matter more than the happy path. It must be impossible to
 * promote the same request twice, because a partner clicking a signed link
 * again would otherwise sell a second room. And a promotion that cannot happen
 * must never undo a confirmation the guest has already been sent — the
 * calendar being wrong is a problem for the team, not for the guest.
 */
class StayPromotionTest extends TestCase
{
    use RefreshDatabase;

    private Listing $listing;

    private RoomType $room;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 09:00:00'));
        Mail::fake();

        $partner = Partner::create(['name' => 'Okonjima', 'email' => 'stay@okonjima.test']);
        $this->listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
        ]);
        $this->room = RoomType::factory()->create([
            'listing_id' => $this->listing->id,
            'code' => 'STD',
            'total_units' => 2,
            'rate_per_night' => 1200,
            'currency' => 'NAD',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_confirming_a_request_puts_the_stay_on_the_calendar(): void
    {
        $inquiry = $this->inquiry();

        app(InquiryDecisionService::class)->confirm($inquiry);

        $stay = Reservation::query()->where('inquiry_id', $inquiry->id)->firstOrFail();

        $this->assertSame(ReservationSource::Website, $stay->source);
        $this->assertSame('Anna Shipanga', $stay->guest_name);
        $this->assertSame('2026-09-10', $stay->check_in->toDateString());
        $this->assertSame('2026-09-13', $stay->check_out->toDateString());

        // And it holds inventory, which is the whole point: one of two rooms
        // is gone for those nights.
        $this->assertSame(1, app(AvailabilityCalendar::class)->unitsFree($this->room, Carbon::parse('2026-09-10')));
    }

    public function test_the_request_stays_a_request(): void
    {
        $inquiry = $this->inquiry();

        app(InquiryDecisionService::class)->confirm($inquiry);

        // Nothing copies back. The inquiry is still the record of what was
        // asked for, and the one-active-request gate still counts it.
        $this->assertSame(InquiryStatus::Confirmed, $inquiry->refresh()->status);
    }

    public function test_a_request_cannot_be_promoted_twice(): void
    {
        $inquiry = $this->inquiry();
        $promoter = app(StayPromoter::class);

        $first = $promoter->promote($inquiry);
        $second = $promoter->promote($inquiry->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Reservation::query()->count());
    }

    public function test_a_request_without_dates_is_not_a_stay_and_says_so(): void
    {
        $inquiry = $this->inquiry(['check_in' => null, 'check_out' => null, 'travel_dates' => 'mid-June, 3 nights']);

        $this->expectException(StayNotPromotableException::class);
        $this->expectExceptionMessage('no check-in and check-out dates');

        app(StayPromoter::class)->promote($inquiry);
    }

    public function test_a_promotion_that_fails_never_undoes_the_confirmation(): void
    {
        $inquiry = $this->inquiry(['check_in' => null, 'check_out' => null]);

        $confirmed = app(InquiryDecisionService::class)->confirm($inquiry);

        $this->assertTrue($confirmed);
        $this->assertSame(InquiryStatus::Confirmed, $inquiry->refresh()->status);
        $this->assertSame(0, Reservation::query()->count());

        // The guest is fine; somebody at NamibWay has to enter the stay by
        // hand, and they are told so today rather than when the guest arrives.
        Mail::assertSent(StayPromotionFailed::class);
    }

    public function test_the_room_is_the_one_the_traveller_picked(): void
    {
        $suite = RoomType::factory()->create([
            'listing_id' => $this->listing->id,
            'code' => 'SUITE',
            'total_units' => 1,
            'rate_per_night' => 3000,
            'currency' => 'NAD',
        ]);

        $stay = app(StayPromoter::class)->promote($this->inquiry(['room_type_code' => 'SUITE']));

        $this->assertSame($suite->id, $stay->units->first()?->room_type_id);
    }

    public function test_a_property_with_one_room_type_needs_no_choice(): void
    {
        // Most requests carry no room type code at all — the picker only
        // appears once a property has entered its rooms.
        $stay = app(StayPromoter::class)->promote($this->inquiry(['room_type_code' => null]));

        $this->assertSame($this->room->id, $stay->units->first()?->room_type_id);
    }

    public function test_a_property_with_several_rooms_is_not_guessed_at(): void
    {
        RoomType::factory()->create([
            'listing_id' => $this->listing->id,
            'code' => 'SUITE',
            'total_units' => 1,
            'rate_per_night' => 3000,
            'currency' => 'NAD',
        ]);

        $this->expectException(StayNotPromotableException::class);
        $this->expectExceptionMessage('does not say which room type');

        app(StayPromoter::class)->promote($this->inquiry(['room_type_code' => null]));
    }

    public function test_the_traveller_is_charged_what_they_were_quoted(): void
    {
        $stay = app(StayPromoter::class)->promote($this->inquiry(['total_amount' => 3300]));

        // The calendar would price this at 3 × 1200. What the guest agreed to
        // is what they pay; the calendar's own figure is kept beside it.
        $this->assertSame(3300.0, $stay->total_amount);
        $this->assertSame(3600.0, $stay->quoted_amount);
    }

    public function test_the_stay_belongs_to_the_travellers_account(): void
    {
        $traveller = User::factory()->create();

        $stay = app(StayPromoter::class)->promote($this->inquiry(['user_id' => $traveller->id]));

        $this->assertSame($traveller->id, $stay->customer?->user_id);
    }

    public function test_the_guest_is_not_written_to_twice(): void
    {
        app(InquiryDecisionService::class)->confirm($this->inquiry());

        // InquiryDecisionService has already sent the confirmation. A second
        // one from the stay would read as a second booking.
        Mail::assertNotSent(GuestStayConfirmed::class);
    }

    /** @param array<string, mixed> $attributes */
    private function inquiry(array $attributes = []): Inquiry
    {
        return Inquiry::create([
            'listing_id' => $this->listing->id,
            'name' => 'Anna Shipanga',
            'email' => 'anna@example.com',
            'phone' => '081 234 5678',
            'check_in' => '2026-09-10',
            'check_out' => '2026-09-13',
            'adults' => 2,
            'children' => 0,
            'status' => InquiryStatus::OnRequest,
            'room_type_code' => 'STD',
            ...$attributes,
        ]);
    }
}
