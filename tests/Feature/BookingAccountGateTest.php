<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\SavedPlan;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CLAUDE.md's account line, as rules the server actually applies rather than
 * things the frontend chooses to offer:
 *
 * - planning and sharing stay open (no account, token only),
 * - saving a plan to an account, and booking, do not,
 * - only the plan's creator may send booking requests for it.
 *
 * Before this, all three were frontend conventions: SavedPlanController::store
 * accepted anonymous saves, and the booking endpoints were unauthenticated with
 * the one-active-request gate keyed on whatever email was typed into the form.
 */
class BookingAccountGateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function plan(): array
    {
        return ['trip_summary' => 'Ten days around Namibia', 'variants' => []];
    }

    private function makePlan(?User $owner = null): SavedPlan
    {
        return SavedPlan::create([
            'title' => 'Ten days',
            'plan_json' => $this->plan(),
            'user_id' => $owner?->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function bookingPayload(
        SavedPlan $plan,
        string $email = 'traveler@example.com',
        ?Listing $stay = null,
    ): array {
        $day = ['date' => now()->addWeek()->toDateString()];

        if ($stay !== null) {
            $day['accommodation'] = ['id' => $stay->id];
        }

        return [
            'name' => 'Traveler',
            'email' => $email,
            'check_in' => now()->addWeek()->toDateString(),
            'check_out' => now()->addWeeks(2)->toDateString(),
            'adults' => 2,
            'variant_name' => 'Classic North',
            'plan' => $this->plan(),
            'variant_days' => [$day],
            'plan_token' => $plan->token,
        ];
    }

    // --- Planning without an account stays frictionless -------------------

    public function test_a_plan_can_still_be_created_and_edited_without_an_account(): void
    {
        $response = $this->postJson(route('kaia.plans.save'), ['variant' => $this->plan()])
            ->assertOk()
            ->assertJsonPath('owned', false);

        $token = $response->json('token');

        $this->patchJson(route('kaia.plans.update', $token), ['variant' => $this->plan()])
            ->assertOk();

        $this->assertNull(SavedPlan::where('token', $token)->first()?->user_id);
    }

    // --- Gap 1: saving to an account is a server rule ---------------------

    public function test_claiming_a_plan_requires_a_login(): void
    {
        $plan = $this->makePlan();

        $this->postJson(route('kaia.plans.claim', $plan->token))->assertUnauthorized();

        $this->assertNull($plan->fresh()?->user_id);
    }

    public function test_a_logged_in_traveler_claims_an_anonymous_plan(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();

        $this->actingAs($user)
            ->postJson(route('kaia.plans.claim', $plan->token))
            ->assertOk()
            ->assertJsonPath('owned', true);

        $this->assertSame($user->id, $plan->fresh()?->user_id);
    }

    public function test_claiming_your_own_plan_twice_is_harmless(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan($user);

        $this->actingAs($user)
            ->postJson(route('kaia.plans.claim', $plan->token))
            ->assertOk();

        $this->assertSame($user->id, $plan->fresh()?->user_id);
    }

    public function test_a_plan_that_belongs_to_someone_else_cannot_be_claimed(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->makePlan($owner);

        $this->actingAs($other)
            ->postJson(route('kaia.plans.claim', $plan->token))
            ->assertForbidden();

        $this->assertSame($owner->id, $plan->fresh()?->user_id);
    }

    public function test_the_read_only_share_token_cannot_claim_the_plan(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();

        // 404, not 403: a share link must not reveal that an editable twin
        // exists behind a different token.
        $this->actingAs($user)
            ->postJson(route('kaia.plans.claim', $plan->share_token))
            ->assertNotFound();

        $this->assertNull($plan->fresh()?->user_id);
    }

    public function test_load_reports_ownership_only_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->makePlan($owner);

        $this->actingAs($owner)
            ->getJson(route('kaia.plans.load', $plan->token))
            ->assertOk()
            ->assertJsonPath('owned', true);

        $this->actingAs($other)
            ->getJson(route('kaia.plans.load', $plan->token))
            ->assertOk()
            ->assertJsonPath('owned', false);
    }

    // --- Gap 2: booking requires an account -------------------------------

    public function test_booking_a_plan_requires_a_login(): void
    {
        $plan = $this->makePlan();

        $this->postJson(route('trips.store'), $this->bookingPayload($plan))
            ->assertUnauthorized();

        $this->assertSame(0, Trip::count());
    }

    public function test_sending_a_listing_inquiry_requires_a_login(): void
    {
        $listing = Listing::factory()->create([
            'is_published' => true,
            'accepts_inquiries' => true,
        ]);

        $this->post(route('listings.inquiries.store', $listing->slug), [
            'name' => 'Traveler',
            'email' => 'traveler@example.com',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, Inquiry::count());
    }

    public function test_sending_a_shortlist_request_requires_a_login(): void
    {
        $listing = Listing::factory()->create([
            'is_published' => true,
            'accepts_inquiries' => true,
        ]);

        $this->post(route('inquiries.batch.store'), [
            'listing_ids' => [$listing->id],
            'name' => 'Traveler',
            'email' => 'traveler@example.com',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, Inquiry::count());
    }

    // --- Gap 3: only the plan's creator may book it -----------------------

    public function test_booking_records_the_account_and_the_plan_it_came_from(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan($user);

        $this->actingAs($user)
            ->postJson(route('trips.store'), $this->bookingPayload($plan))
            ->assertOk();

        $trip = Trip::firstOrFail();
        $this->assertSame($user->id, $trip->user_id);
        $this->assertSame($plan->id, $trip->saved_plan_id);
    }

    public function test_booking_an_anonymous_plan_claims_it_for_the_booker(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();

        $this->actingAs($user)
            ->postJson(route('trips.store'), $this->bookingPayload($plan))
            ->assertOk();

        $this->assertSame($user->id, $plan->fresh()?->user_id);
    }

    public function test_someone_elses_plan_cannot_be_booked(): void
    {
        $owner = User::factory()->create();
        $coPlanner = User::factory()->create();
        $plan = $this->makePlan($owner);

        $this->actingAs($coPlanner)
            ->postJson(route('trips.store'), $this->bookingPayload($plan))
            ->assertForbidden();

        $this->assertSame(0, Trip::count());
    }

    public function test_a_read_only_share_token_cannot_book(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan();

        $payload = $this->bookingPayload($plan);
        $payload['plan_token'] = $plan->share_token;

        $this->actingAs($user)
            ->postJson(route('trips.store'), $payload)
            ->assertForbidden();

        $this->assertSame(0, Trip::count());
        $this->assertNull($plan->fresh()?->user_id);
    }

    public function test_booking_without_a_plan_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan($user);

        $payload = $this->bookingPayload($plan);
        unset($payload['plan_token']);

        $this->actingAs($user)
            ->postJson(route('trips.store'), $payload)
            ->assertStatus(422);

        $this->assertSame(0, Trip::count());
    }

    // --- The one-active-request gate now follows the account --------------

    public function test_a_second_pipeline_is_blocked_even_under_a_different_email(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create([
            'is_published' => true,
            'accepts_inquiries' => true,
        ]);

        $listing->inquiries()->create([
            'user_id' => $user->id,
            'name' => 'Traveler',
            'email' => 'first@example.com',
            'status' => InquiryStatus::Pending,
        ]);

        // Keying the gate on the submitted email alone was the hole: the same
        // traveler simply typed a second address.
        $plan = $this->makePlan($user);

        $this->actingAs($user)
            ->postJson(route('trips.store'), $this->bookingPayload($plan, 'second@example.com'))
            ->assertStatus(422);

        $this->assertSame(0, Trip::count());
    }

    public function test_the_gate_still_catches_a_matching_email_without_an_account(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create([
            'is_published' => true,
            'accepts_inquiries' => true,
        ]);

        // No user_id — an inquiry from before booking required an account.
        $listing->inquiries()->create([
            'name' => 'Traveler',
            'email' => 'traveler@example.com',
            'status' => InquiryStatus::Pending,
        ]);

        $plan = $this->makePlan($user);

        $this->actingAs($user)
            ->postJson(route('trips.store'), $this->bookingPayload($plan))
            ->assertStatus(422);
    }

    // --- A trip's request statuses are its owner's alone ------------------

    public function test_another_account_cannot_read_a_trips_inquiry_statuses(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->makePlan($owner);
        $stay = Listing::factory()->create([
            'is_published' => true,
            'accepts_inquiries' => true,
        ]);

        $this->actingAs($owner)
            ->postJson(route('trips.store'), $this->bookingPayload($plan, 'traveler@example.com', $stay))
            ->assertOk()
            ->assertJsonPath('inquiry_count', 1);

        $trip = Trip::firstOrFail();
        $this->assertSame($owner->id, $trip->inquiries()->first()?->user_id);

        $this->actingAs($owner)
            ->getJson(route('trips.inquiries', $trip))
            ->assertOk();

        $this->actingAs($other)
            ->getJson(route('trips.inquiries', $trip))
            ->assertForbidden();
    }
}
