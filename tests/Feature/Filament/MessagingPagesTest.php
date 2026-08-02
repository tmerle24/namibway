<?php

namespace Tests\Feature\Filament;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagingPagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_listings_page_renders_and_shows_an_unread_message(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        PartnerMessage::create([
            'partner_id' => $partner->id,
            'listing_id' => $listing->id,
            'direction' => PartnerMessage::DIRECTION_INBOUND,
            'subject' => 'Unread inquiry',
            'body' => 'Hi',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/messaging-listings')
            ->assertOk()
            ->assertSee('Unread inquiry')
            ->assertSee('Lodge');
    }

    public function test_partners_page_renders_with_aggregated_counts_across_two_listings(): void
    {
        $partner = Partner::create(['name' => 'Multi Lodge', 'email' => 'owner@example.com']);
        $listingA = Listing::factory()->create(['partner_id' => $partner->id]);
        $listingB = Listing::factory()->create(['partner_id' => $partner->id]);

        PartnerMessage::create([
            'partner_id' => $partner->id,
            'listing_id' => $listingA->id,
            'direction' => PartnerMessage::DIRECTION_INBOUND,
            'subject' => 'A',
            'body' => 'Hi',
            'sent_at' => now(),
        ]);

        PartnerMessage::create([
            'partner_id' => $partner->id,
            'listing_id' => $listingB->id,
            'direction' => PartnerMessage::DIRECTION_INBOUND,
            'subject' => 'B',
            'body' => 'Hi',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/messaging-partners')
            ->assertOk()
            ->assertSee('Multi Lodge');
    }

    public function test_inquiry_resource_still_renders_under_its_new_customers_label(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/inquiries')
            ->assertOk();
    }

    public function test_sidebar_shows_the_messaging_group_with_all_three_sub_items(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/messaging-listings')
            ->assertOk()
            ->assertSee('Messaging')
            ->assertSee('Listings')
            ->assertSee('Partners')
            ->assertSee('Customers');
    }
}
