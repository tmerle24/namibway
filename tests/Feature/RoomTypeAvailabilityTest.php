<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\RoomType;
use App\Models\SavedPlan;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The trip plan's room picker used to invent its three options client-side by
 * scaling the property's `price_from` — plausible numbers next to a Book
 * button. It now asks the server what the listing actually has, which makes
 * these the rules that matter: real rates, real remaining units, an empty
 * answer rather than a substitute, and the chosen room reaching the booking.
 */
class RoomTypeAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function listing(): Listing
    {
        return Listing::factory()->create([
            'is_published' => true,
            'accepts_inquiries' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function room(Listing $listing, array $attributes = []): RoomType
    {
        return RoomType::create([
            'listing_id' => $listing->id,
            'code' => 'standard',
            'name' => 'Standard Double',
            'max_adults' => 2,
            'max_children' => 0,
            'total_units' => 2,
            'rate_per_night' => 1200,
            'currency' => 'NAD',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function url(Listing $listing, string $checkIn = '2026-09-01', string $checkOut = '2026-09-04', int $adults = 2, int $children = 0): string
    {
        return route('listings.room-types', $listing->slug)
            ."?check_in={$checkIn}&check_out={$checkOut}&adults={$adults}&children={$children}";
    }

    public function test_it_returns_the_real_rate_units_and_night_count(): void
    {
        $listing = $this->listing();
        $this->room($listing, ['gallery' => ['room-types/gallery/double.jpg']]);

        $this->getJson($this->url($listing))
            ->assertOk()
            ->assertJsonPath('nights', 3)
            ->assertJsonPath('rooms.0.code', 'standard')
            ->assertJsonPath('rooms.0.price_per_night', 1200)
            ->assertJsonPath('rooms.0.total_price', 3600)
            ->assertJsonPath('rooms.0.units_left', 2)
            ->assertJsonCount(1, 'rooms.0.gallery');
    }

    public function test_a_listing_with_no_room_types_returns_nothing_rather_than_a_substitute(): void
    {
        // The ordinary case today, and the whole point: no invented tiers.
        $this->getJson($this->url($this->listing()))
            ->assertOk()
            ->assertJsonCount(0, 'rooms');
    }

    public function test_overlapping_requests_reduce_the_units_left(): void
    {
        $listing = $this->listing();
        $this->room($listing, ['total_units' => 2]);

        $listing->inquiries()->create([
            'name' => 'Someone else',
            'email' => 'other@example.com',
            'room_type_code' => 'standard',
            'check_in' => '2026-09-02',
            'check_out' => '2026-09-03',
            'status' => InquiryStatus::Confirmed,
        ]);

        $this->getJson($this->url($listing))
            ->assertOk()
            ->assertJsonPath('rooms.0.units_left', 1);
    }

    public function test_a_stay_ending_when_another_begins_does_not_overlap_it(): void
    {
        $listing = $this->listing();
        $this->room($listing, ['total_units' => 1]);

        // Checks out the morning our stay checks in — the room turns over.
        $listing->inquiries()->create([
            'name' => 'Someone else',
            'email' => 'other@example.com',
            'room_type_code' => 'standard',
            'check_in' => '2026-08-29',
            'check_out' => '2026-09-01',
            'status' => InquiryStatus::Confirmed,
        ]);

        $this->getJson($this->url($listing))
            ->assertOk()
            ->assertJsonPath('rooms.0.units_left', 1);
    }

    public function test_a_sold_out_room_is_left_out_entirely(): void
    {
        $listing = $this->listing();
        $this->room($listing, ['total_units' => 1]);

        $listing->inquiries()->create([
            'name' => 'Someone else',
            'email' => 'other@example.com',
            'room_type_code' => 'standard',
            'check_in' => '2026-09-02',
            'check_out' => '2026-09-03',
            'status' => InquiryStatus::OnRequest,
        ]);

        $this->getJson($this->url($listing))
            ->assertOk()
            ->assertJsonCount(0, 'rooms');
    }

    public function test_a_cancelled_request_frees_the_room_again(): void
    {
        $listing = $this->listing();
        $this->room($listing, ['total_units' => 1]);

        $listing->inquiries()->create([
            'name' => 'Someone else',
            'email' => 'other@example.com',
            'room_type_code' => 'standard',
            'check_in' => '2026-09-02',
            'check_out' => '2026-09-03',
            'status' => InquiryStatus::Cancelled,
        ]);

        $this->getJson($this->url($listing))
            ->assertOk()
            ->assertJsonPath('rooms.0.units_left', 1);
    }

    public function test_a_room_too_small_for_the_party_is_left_out(): void
    {
        $listing = $this->listing();
        $this->room($listing, ['max_adults' => 2, 'max_children' => 0]);

        $this->getJson($this->url($listing, adults: 3))
            ->assertOk()
            ->assertJsonCount(0, 'rooms');
    }

    public function test_a_child_may_take_a_spare_adult_slot(): void
    {
        $listing = $this->listing();
        $this->room($listing, ['max_adults' => 3, 'max_children' => 0]);

        // Two adults and a child in a room for three: it fits, even though the
        // room declares no child capacity of its own.
        $this->getJson($this->url($listing, adults: 2, children: 1))
            ->assertOk()
            ->assertJsonCount(1, 'rooms');
    }

    public function test_an_inactive_room_is_never_offered(): void
    {
        $listing = $this->listing();
        $this->room($listing, ['is_active' => false]);

        $this->getJson($this->url($listing))
            ->assertOk()
            ->assertJsonCount(0, 'rooms');
    }

    public function test_an_unpublished_listing_is_not_readable(): void
    {
        $listing = Listing::factory()->create(['is_published' => false]);
        $this->room($listing);

        $this->getJson($this->url($listing))->assertNotFound();
    }

    public function test_past_dates_are_answered_rather_than_rejected(): void
    {
        // A saved plan can have dates that have since passed. A 422 there is a
        // dead end the traveler can do nothing about.
        $listing = $this->listing();
        $this->room($listing);

        $this->getJson($this->url($listing, checkIn: '2020-01-01', checkOut: '2020-01-03'))
            ->assertOk();
    }

    public function test_a_zero_night_range_is_rejected(): void
    {
        $listing = $this->listing();
        $this->room($listing);

        $this->getJson($this->url($listing, checkIn: '2026-09-01', checkOut: '2026-09-01'))
            ->assertStatus(422);
    }

    public function test_the_chosen_room_reaches_the_booking_request(): void
    {
        $user = User::factory()->create();
        $listing = $this->listing();
        $this->room($listing);

        $plan = SavedPlan::create([
            'title' => 'Trip',
            'plan_json' => ['trip_summary' => 'Trip', 'variants' => []],
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('trips.store'), [
                'name' => 'Traveler',
                'email' => 'traveler@example.com',
                'check_in' => now()->addWeek()->toDateString(),
                'check_out' => now()->addWeeks(2)->toDateString(),
                'adults' => 2,
                'variant_name' => 'Classic',
                'plan' => ['trip_summary' => 'Trip', 'variants' => []],
                'variant_days' => [
                    [
                        'accommodation' => ['id' => $listing->id],
                        'room_selection' => ['code' => 'standard', 'name' => 'Standard Double'],
                    ],
                    // Second night of the same stay — one inquiry, not two.
                    [
                        'accommodation' => ['id' => $listing->id],
                        'room_selection' => ['code' => 'standard', 'name' => 'Standard Double'],
                    ],
                ],
                'plan_token' => $plan->token,
            ])
            ->assertOk()
            ->assertJsonPath('inquiry_count', 1);

        $inquiry = Trip::firstOrFail()->inquiries()->firstOrFail();
        $this->assertSame('standard', $inquiry->room_type_code);
    }

    /**
     * Capacity is a rule on this path and not a filter.
     *
     * The picker only ever declined to *offer* a room the party does not fit,
     * which a party that grows after the room was chosen walks straight past —
     * and a request posted directly never met the picker at all. Nobody is
     * standing here to judge whether the room can take a fourth child, which is
     * exactly what makes this different from the desk. See BOOKING_SYSTEM.md.
     */
    public function test_a_room_too_small_for_the_party_is_refused_with_the_numbers(): void
    {
        $user = User::factory()->create();
        $listing = $this->listing();
        $this->room($listing, ['max_adults' => 2, 'max_children' => 2]);

        $plan = SavedPlan::create([
            'title' => 'Trip',
            'plan_json' => ['trip_summary' => 'Trip', 'variants' => []],
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('trips.store'), [
                'name' => 'Traveler',
                'email' => 'traveler@example.com',
                'check_in' => now()->addWeek()->toDateString(),
                'check_out' => now()->addWeeks(2)->toDateString(),
                'adults' => 2,
                'children' => 3,
                'variant_name' => 'Classic',
                'plan' => ['trip_summary' => 'Trip', 'variants' => []],
                'variant_days' => [[
                    'accommodation' => ['id' => $listing->id],
                    'room_selection' => ['code' => 'standard', 'name' => 'Standard Double'],
                ]],
                'plan_token' => $plan->token,
            ])
            ->assertStatus(422)
            ->assertJsonPath('rooms.0', 'Standard Double sleeps 2 + 2, and this booking is 2 + 3.');

        // Nothing was written: no trip, and no request sent to the property.
        $this->assertSame(0, Trip::query()->count());
        $this->assertSame(0, Inquiry::query()->count());
    }

    /**
     * A plan whose room type has been retired since goes to the property
     * without a room on it — which is what a plan with no picker does anyway,
     * and is a question the partner can answer.
     */
    public function test_a_room_code_that_names_nothing_is_not_a_capacity_refusal(): void
    {
        $user = User::factory()->create();
        $listing = $this->listing();
        $this->room($listing, ['max_adults' => 2, 'max_children' => 0]);

        $plan = SavedPlan::create([
            'title' => 'Trip',
            'plan_json' => ['trip_summary' => 'Trip', 'variants' => []],
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('trips.store'), [
                'name' => 'Traveler',
                'email' => 'traveler@example.com',
                'check_in' => now()->addWeek()->toDateString(),
                'check_out' => now()->addWeeks(2)->toDateString(),
                'adults' => 4,
                'variant_name' => 'Classic',
                'plan' => ['trip_summary' => 'Trip', 'variants' => []],
                'variant_days' => [[
                    'accommodation' => ['id' => $listing->id],
                    'room_selection' => ['code' => 'gone-since', 'name' => 'Retired'],
                ]],
                'plan_token' => $plan->token,
            ])
            ->assertOk();

        $this->assertSame('gone-since', Trip::firstOrFail()->inquiries()->firstOrFail()->room_type_code);
    }

    public function test_a_stay_with_no_room_chosen_still_books_without_one(): void
    {
        $user = User::factory()->create();
        $listing = $this->listing();

        $plan = SavedPlan::create([
            'title' => 'Trip',
            'plan_json' => ['trip_summary' => 'Trip', 'variants' => []],
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('trips.store'), [
                'name' => 'Traveler',
                'email' => 'traveler@example.com',
                'check_in' => now()->addWeek()->toDateString(),
                'check_out' => now()->addWeeks(2)->toDateString(),
                'adults' => 2,
                'variant_name' => 'Classic',
                'plan' => ['trip_summary' => 'Trip', 'variants' => []],
                'variant_days' => [['accommodation' => ['id' => $listing->id]]],
                'plan_token' => $plan->token,
            ])
            ->assertOk();

        $this->assertNull(Trip::firstOrFail()->inquiries()->firstOrFail()->room_type_code);
    }
}
