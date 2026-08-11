<?php

namespace Tests\Feature\Booking;

use App\Enums\InquiryStatus;
use App\Enums\ListingType;
use App\Enums\StayStatus;
use App\Jobs\ExpireNativeHoldJob;
use App\Models\BookableUnit;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Services\Booking\InquiryDecisionService;
use App\Services\Booking\RoomAvailability;
use App\Services\Booking\StayPromoter;
use App\Services\Inventory\AvailabilityCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A request holds the room it asks for.
 *
 * Before this, "hold" was a timestamp on the inquiry and nothing else: the
 * room stayed on sale everywhere, and the lodge could sell it at its own desk
 * while the traveler waited for an answer. The hold is now a provisional stay
 * on the calendar — real inventory, released when the request is declined or
 * expires, and turned into the guest's own stay when it is confirmed.
 */
class SoftHoldTest extends TestCase
{
    use RefreshDatabase;

    private Listing $listing;

    private BookableUnit $room;

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
        $this->room = BookableUnit::factory()->create([
            'listing_id' => $this->listing->id,
            'code' => 'STD',
            'total_units' => 1,
            'rate_per_night' => 1200,
            'currency' => 'NAD',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_request_takes_the_room_off_the_calendar_while_the_partner_decides(): void
    {
        $inquiry = $this->inquiry();

        $held = app(StayPromoter::class)->hold($inquiry);

        $this->assertNotNull($held);
        $this->assertSame(StayStatus::Provisional, $held->status);
        $this->assertSame(0, app(AvailabilityCalendar::class)->unitsFree($this->room, Carbon::parse('2026-09-10')));
    }

    public function test_the_same_room_is_not_subtracted_twice(): void
    {
        $inquiry = $this->inquiry();

        // Counted once by the inquiry before the hold, once by the calendar
        // after it. The answer is zero either way, never minus one.
        $this->assertSame(0, $this->unitsLeft());

        app(StayPromoter::class)->hold($inquiry);

        $this->assertSame(0, $this->unitsLeft());
    }

    public function test_confirming_turns_the_hold_into_the_guests_stay_rather_than_a_second_one(): void
    {
        $inquiry = $this->inquiry();
        $held = app(StayPromoter::class)->hold($inquiry);

        app(InquiryDecisionService::class)->confirm($inquiry);

        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(StayStatus::Confirmed, $held->refresh()->status);

        // And the room it was holding is the room the guest keeps — the
        // inventory never moved.
        $this->assertSame(0, app(AvailabilityCalendar::class)->unitsFree($this->room, Carbon::parse('2026-09-10')));
    }

    public function test_declining_gives_the_room_back(): void
    {
        $inquiry = $this->inquiry();
        app(StayPromoter::class)->hold($inquiry);

        app(InquiryDecisionService::class)->decline($inquiry);

        $this->assertSame(1, app(AvailabilityCalendar::class)->unitsFree($this->room, Carbon::parse('2026-09-10')));
        $this->assertSame(StayStatus::Cancelled, Reservation::query()->firstOrFail()->status);
    }

    public function test_an_expired_hold_gives_the_room_back(): void
    {
        $inquiry = $this->inquiry(['hold_expires_at' => now()->addHours(2)]);
        app(StayPromoter::class)->hold($inquiry);

        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00'));

        (new ExpireNativeHoldJob($inquiry->id))->handle();

        $this->assertSame(InquiryStatus::Cancelled, $inquiry->refresh()->status);
        $this->assertSame(1, app(AvailabilityCalendar::class)->unitsFree($this->room, Carbon::parse('2026-09-10')));
    }

    public function test_a_hold_nobody_could_take_leaves_the_request_alone(): void
    {
        // The room is gone before the request gets to it.
        app(StayPromoter::class)->hold($this->inquiry());

        $second = $this->inquiry(['email' => 'other@example.com']);

        $this->assertNull(app(StayPromoter::class)->hold($second));
        $this->assertSame(InquiryStatus::OnRequest, $second->refresh()->status);
    }

    public function test_holding_twice_holds_once(): void
    {
        $inquiry = $this->inquiry();

        $first = app(StayPromoter::class)->hold($inquiry);
        $second = app(StayPromoter::class)->hold($inquiry->refresh());

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, Reservation::query()->count());
    }

    private function unitsLeft(): int
    {
        return RoomAvailability::unitsLeft(
            $this->listing->id,
            $this->room,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-13'),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function inquiry(array $attributes = []): Inquiry
    {
        return Inquiry::create([
            'listing_id' => $this->listing->id,
            'name' => 'Anna Shipanga',
            'email' => 'anna@example.com',
            'check_in' => '2026-09-10',
            'check_out' => '2026-09-13',
            'adults' => 2,
            'children' => 0,
            'status' => InquiryStatus::OnRequest,
            'bookable_unit_code' => 'STD',
            ...$attributes,
        ]);
    }
}
