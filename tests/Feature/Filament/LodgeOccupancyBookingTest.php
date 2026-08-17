<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Enums\PricingStrategy;
use App\Enums\ReservationSource;
use App\Filament\Partner\Pages\OccupancyCalendar;
use App\Filament\Partner\Pages\RatesAndAvailability;
use App\Models\BookableUnit;
use App\Models\GuestCategory;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\RatePlan;
use App\Models\RatePlanGuestAmount;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use App\Services\Inventory\InventoryWriter;
use App\Services\Pricing\GuestCategoryDefaults;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The booking form when a property prices by who is in the room.
 *
 * The two behaviours that decide whether this is usable: the form asks for
 * guests *only* where the answer changes a price, and a room line with guests
 * is one room — so a second room is a second line with its own occupants,
 * which is what makes two different families on one booking priceable at all.
 */
class LodgeOccupancyBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_per_room_property_still_books_by_units(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Guesthouse');
        $room = $this->bookableUnit($listing);
        $plan = $this->plan($listing, PricingStrategy::PerUnit);

        app(InventoryWriter::class)->setRates($plan, $room, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-15'), ['rate' => 1800]);

        $this->asPartner($user);

        $this->submitBooking([
            'check_in' => '2026-09-14',
            'check_out' => '2026-09-16',
            'rooms' => [['bookable_unit_id' => $room->id, 'quantity' => 2]],
            'guest_name' => 'Two Rooms',
            'source' => ReservationSource::WalkIn->value,
        ]);

        $reservation = Reservation::where('listing_id', $listing->id)->firstOrFail();

        // Two rooms, two nights, one price for the room whoever is in it.
        $this->assertSame(7200.0, $reservation->total_amount);
        $this->assertSame(2, $reservation->units->first()->quantity);
        $this->assertSame(0, ReservationGuest::count());
    }

    public function test_a_lodge_pricing_by_guests_books_a_room_at_a_time(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Lodge');
        $room = $this->bookableUnit($listing);
        $plan = $this->plan($listing, PricingStrategy::PerOccupancy);
        $categories = $this->categories($listing);

        app(InventoryWriter::class)->setGuestAmounts(
            $plan,
            $room,
            Carbon::parse('2026-09-14'),
            Carbon::parse('2026-09-15'),
            [1 => 1000, 2 => 1300, 3 => 1500],
        );

        $this->asPartner($user);

        $this->submitBooking([
            'check_in' => '2026-09-14',
            'check_out' => '2026-09-15',
            'rooms' => [
                ['bookable_unit_id' => $room->id, 'guests' => [
                    ['guest_category_id' => $categories['AD']->id, 'count' => 2],
                ]],
                ['bookable_unit_id' => $room->id, 'guests' => [
                    ['guest_category_id' => $categories['AD']->id, 'count' => 2],
                    ['guest_category_id' => $categories['CH']->id, 'count' => 1],
                ]],
            ],
            'guest_name' => 'Two Families',
            'source' => ReservationSource::Telephone->value,
        ]);

        $reservation = Reservation::where('listing_id', $listing->id)->firstOrFail();

        // Two lines, not one of two units: 1300 for the couple and 1500 for
        // the family. Merged, both would have been priced the same.
        $this->assertCount(2, $reservation->units);
        $this->assertSame(2800.0, $reservation->total_amount);
        $this->assertSame([1, 1], $reservation->units->pluck('quantity')->all());

        $counts = ReservationGuest::query()->orderBy('id')->pluck('count')->all();
        $this->assertSame([2, 2, 1], $counts);
    }

    public function test_a_stay_nobody_priced_is_refused_with_the_reason(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Lodge');
        $room = $this->bookableUnit($listing);
        $plan = $this->plan($listing, PricingStrategy::PerOccupancy);
        $categories = $this->categories($listing);

        app(InventoryWriter::class)->setGuestAmounts(
            $plan,
            $room,
            Carbon::parse('2026-09-14'),
            Carbon::parse('2026-09-15'),
            [1 => 1000, 2 => 1300],
        );

        $this->asPartner($user);

        $this->submitBooking([
            'check_in' => '2026-09-14',
            'check_out' => '2026-09-15',
            'rooms' => [
                ['bookable_unit_id' => $room->id, 'guests' => [
                    ['guest_category_id' => $categories['AD']->id, 'count' => 4],
                ]],
            ],
            'guest_name' => 'Four Adults',
            'source' => ReservationSource::WalkIn->value,
        ]);

        // Nothing written, and the calendar untouched.
        $this->assertSame(0, Reservation::count());
    }

    public function test_the_rates_screen_writes_prices_by_guest_count(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Lodge');
        $room = $this->bookableUnit($listing);
        $plan = $this->plan($listing, PricingStrategy::PerOccupancy);
        $this->categories($listing);

        $this->asPartner($user);

        Livewire::test(RatesAndAvailability::class)
            ->fillForm([
                'rate_plan_id' => $plan->id,
                'bookable_unit_ids' => [$room->id],
                'from' => '2026-09-14',
                'to' => '2026-09-16',
                'guest_amounts' => [
                    ['guests' => 1, 'amount' => 1000, 'remove' => false],
                    ['guests' => 2, 'amount' => 1300, 'remove' => false],
                ],
            ])
            ->call('apply');

        $rows = RatePlanGuestAmount::query()
            ->where('rate_plan_id', $plan->id)
            ->whereDate('date', '2026-09-14')
            ->pluck('amount', 'guests');

        $this->assertSame(1000.0, $rows[1]);
        $this->assertSame(1300.0, $rows[2]);
        $this->assertSame(6, RatePlanGuestAmount::where('rate_plan_id', $plan->id)->count());
    }

    public function test_the_rate_select_writes_to_the_plan_it_names(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Lodge');
        $room = $this->bookableUnit($listing);
        $standard = $this->plan($listing, PricingStrategy::PerOccupancy);
        $resident = RatePlan::create([
            'listing_id' => $listing->id,
            'name' => 'Namibian resident',
            'code' => 'RES',
            'pricing_strategy' => PricingStrategy::PerOccupancy,
        ]);

        $this->asPartner($user);

        Livewire::test(RatesAndAvailability::class)
            ->fillForm([
                'rate_plan_id' => $resident->id,
                'bookable_unit_ids' => [$room->id],
                'from' => '2026-09-14',
                'to' => '2026-09-14',
                'guest_amounts' => [['guests' => 2, 'amount' => 900, 'remove' => false]],
            ])
            ->call('apply');

        $this->assertSame(900.0, RatePlanGuestAmount::where('rate_plan_id', $resident->id)->value('amount'));
        $this->assertSame(0, RatePlanGuestAmount::where('rate_plan_id', $standard->id)->count());
    }

    public function test_another_partners_rate_plan_cannot_be_priced(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $myRoom = $this->bookableUnit($myListing);
        $this->plan($myListing, PricingStrategy::PerOccupancy);

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $theirPlan = $this->plan($theirListing, PricingStrategy::PerOccupancy);

        $this->asPartner($mine);

        Livewire::test(RatesAndAvailability::class)
            ->fillForm([
                'rate_plan_id' => $theirPlan->id,
                'bookable_unit_ids' => [$myRoom->id],
                'from' => '2026-09-14',
                'to' => '2026-09-14',
                'guest_amounts' => [['guests' => 2, 'amount' => 99, 'remove' => false]],
            ])
            ->call('apply');

        // An id from a browser selects one of this property's own plans or
        // nothing at all — never somebody else's.
        $this->assertSame(0, RatePlanGuestAmount::where('rate_plan_id', $theirPlan->id)->count());
    }

    public function test_the_calendar_shows_the_rate_plan_it_is_asked_for(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Lodge');
        $room = $this->bookableUnit($listing);

        $resident = $this->plan($listing, PricingStrategy::PerUnit);
        $international = RatePlan::create([
            'listing_id' => $listing->id,
            'name' => 'International',
            'code' => 'INT',
            'pricing_strategy' => PricingStrategy::PerUnit,
        ]);

        $writer = app(InventoryWriter::class);
        // The whole month the calendar opens on: the range snaps to the 1st,
        // so a season starting on the 10th would leave the first cell showing
        // the room's own rate and prove nothing about rate plans.
        $from = Carbon::parse('2026-09-01');
        $to = Carbon::parse('2026-09-30');

        $writer->setRates($resident, $room, $from, $to, ['rate' => 900]);
        $writer->setRates($international, $room, $from, $to, ['rate' => 2400]);

        $this->asPartner($user);

        $page = Livewire::test(OccupancyCalendar::class);

        // The default until somebody switches, and the switch changes the
        // prices and nothing else — a room is still sold once.
        $this->assertSame(900.0, $page->instance()->grid()?->rows[0]->cells[0]->rate);

        $page->call('showRatePlan', $international->id);

        $this->assertSame(2400.0, $page->instance()->grid()?->rows[0]->cells[0]->rate);
        $this->assertSame(4, $page->instance()->grid()?->rows[0]->cells[0]->unitsFree);
    }

    public function test_another_partners_rate_plan_cannot_be_shown_on_the_calendar(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $myRoom = $this->bookableUnit($myListing);
        $myPlan = $this->plan($myListing, PricingStrategy::PerUnit);

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $theirRoom = $this->bookableUnit($theirListing);
        $theirPlan = $this->plan($theirListing, PricingStrategy::PerUnit);

        $writer = app(InventoryWriter::class);
        // The whole month the calendar opens on: the range snaps to the 1st,
        // so a season starting on the 10th would leave the first cell showing
        // the room's own rate and prove nothing about rate plans.
        $from = Carbon::parse('2026-09-01');
        $to = Carbon::parse('2026-09-30');

        $writer->setRates($myPlan, $myRoom, $from, $to, ['rate' => 900]);
        $writer->setRates($theirPlan, $theirRoom, $from, $to, ['rate' => 5555]);

        $this->asPartner($mine);

        $page = Livewire::test(OccupancyCalendar::class)->call('showRatePlan', $theirPlan->id);

        // Falls back to this property's own plan rather than showing another
        // lodge's prices.
        $this->assertSame(900.0, $page->instance()->grid()?->rows[0]->cells[0]->rate);
        $this->assertSame($myPlan->id, $page->instance()->shownRatePlan()?->id);
    }

    /**
     * The desk half of the capacity rule: warned, and asked what it is doing
     * about it, but never refused. A receptionist told "no" by software they
     * cannot argue with writes the booking on paper, and then the property's
     * own system does not know about the guest at all.
     */
    public function test_the_desk_may_overfill_a_room_once_it_says_what_it_is_doing(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Lodge');
        $room = $this->bookableUnit($listing);

        $this->asPartner($user);

        // The room sleeps 4 + 2. Seven people do not fit however they arrange
        // themselves, and the form asks about it rather than shrugging.
        $this->submitBooking([
            'check_in' => '2026-09-14',
            'check_out' => '2026-09-16',
            'guest_name' => 'Familie Braun',
            'source' => ReservationSource::WalkIn->value,
            'adults' => 4,
            'children' => 3,
            'rooms' => [['bookable_unit_id' => $room->id, 'quantity' => 1]],
            'over_capacity_note' => 'Cot in the chalet',
        ]);

        $reservation = Reservation::query()->latest('id')->firstOrFail();

        $this->assertSame('Cot in the chalet', $reservation->over_capacity_note);
        $this->assertSame(7, $reservation->adults + $reservation->children);

        // And it is on the board somebody makes the room up from.
        $this->actingAs($user)
            ->get('/partner/arrivals?date=2026-09-14')
            ->assertOk()
            ->assertSee('Cot in the chalet');
    }

    public function test_a_booking_that_fits_is_never_asked_about_beds(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Lodge');
        $room = $this->bookableUnit($listing);

        $this->asPartner($user);

        $this->submitBooking([
            'check_in' => '2026-09-14',
            'check_out' => '2026-09-16',
            'guest_name' => 'Fits Fine',
            'source' => ReservationSource::WalkIn->value,
            'adults' => 2,
            'children' => 1,
            'rooms' => [['bookable_unit_id' => $room->id, 'quantity' => 1]],
        ]);

        $this->assertNull(Reservation::query()->latest('id')->firstOrFail()->over_capacity_note);
    }

    /**
     * Open the booking form and submit it.
     *
     * Not Filament's callAction() helper: that one dot-flattens the data it is
     * given, so `rooms` arrives as `rooms.0.…` and lands *beside* the row the
     * form was prefilled with rather than replacing it — a booking for two
     * rooms would quietly become three, and the test would be asserting
     * against the helper rather than against the screen. Setting each
     * top-level key whole avoids the question.
     *
     * @param  array<string, mixed>  $data
     */
    private function submitBooking(array $data): void
    {
        $page = Livewire::test(OccupancyCalendar::class)->mountAction('createBooking');

        foreach ($data as $key => $value) {
            $page->set("mountedActionsData.0.{$key}", $value);
        }

        $page->callMountedAction();
    }

    private function asPartner(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function partnerWithProperty(string $name): array
    {
        $partner = Partner::create(['name' => $name, 'email' => fake()->unique()->safeEmail()]);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => $name,
        ]);

        return [$user, $listing];
    }

    private function bookableUnit(Listing $listing): BookableUnit
    {
        return BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'name' => 'Standard Chalet',
            'max_adults' => 4,
            'max_children' => 2,
            'total_units' => 4,
            'base_rate' => 1200,
            'currency' => 'NAD',
        ]);
    }

    private function plan(Listing $listing, PricingStrategy $strategy): RatePlan
    {
        return RatePlan::create([
            'listing_id' => $listing->id,
            'name' => 'Standard rate',
            'code' => 'STD',
            'pricing_strategy' => $strategy,
            'is_default' => true,
        ]);
    }

    /** @return array<string, GuestCategory> */
    private function categories(Listing $listing): array
    {
        $keyed = [];

        foreach (GuestCategoryDefaults::ensureFor($listing) as $category) {
            $keyed[$category->code] = $category;
        }

        return $keyed;
    }
}
