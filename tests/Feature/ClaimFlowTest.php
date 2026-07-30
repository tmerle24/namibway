<?php

namespace Tests\Feature;

use App\Mail\PartnerDeclinedListing;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClaimFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_claim_a_scraped_listing(): void
    {
        $partner = Partner::create(['name' => 'Test Lodge', 'claim_token' => 'test-token']);
        Listing::factory()->for($partner)->create(['claim_status' => 'unclaimed']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('claim.store', 'test-token'))
            ->assertRedirect(route('dashboard'));

        $partner->refresh();
        $this->assertNotNull($partner->claimed_at);
        $this->assertSame('claimed', $partner->listings()->first()->claim_status);
    }

    public function test_owner_can_decline_a_scraped_listing(): void
    {
        Mail::fake();

        $partner = Partner::create(['name' => 'Test Lodge', 'claim_token' => 'test-token', 'email' => 'owner@example.com']);
        $listing = Listing::factory()->for($partner)->create([
            'claim_status' => 'unclaimed',
            'is_published' => true,
        ]);

        $this->post(route('claim.decline', 'test-token'))
            ->assertRedirect(route('claim.decline.show', 'test-token'));

        $partner->refresh();
        $listing->refresh();

        $this->assertNotNull($partner->claim_rejected_at);
        $this->assertSame('rejected', $listing->claim_status);
        $this->assertFalse($listing->is_published);

        Mail::assertQueued(PartnerDeclinedListing::class);
    }

    public function test_declining_twice_does_not_send_a_second_notification(): void
    {
        Mail::fake();

        $partner = Partner::create(['name' => 'Test Lodge', 'claim_token' => 'test-token', 'email' => 'owner@example.com']);
        Listing::factory()->for($partner)->create(['claim_status' => 'unclaimed']);

        $this->post(route('claim.decline', 'test-token'));
        $this->post(route('claim.decline', 'test-token'));

        Mail::assertQueued(PartnerDeclinedListing::class, 1);
    }
}
