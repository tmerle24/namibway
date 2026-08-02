<?php

namespace Tests\Feature\Services;

use App\Models\Listing;
use App\Models\Partner;
use App\Services\Messaging\PartnerEmailFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerEmailFetcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_by_listing_contact_email_first(): void
    {
        $partner = Partner::create(['name' => 'Lodge Group', 'email' => 'shared@example.com']);
        $listingA = Listing::factory()->create(['partner_id' => $partner->id, 'contact_email' => 'lodge-a@example.com']);
        Listing::factory()->create(['partner_id' => $partner->id, 'contact_email' => 'lodge-b@example.com']);

        $result = app(PartnerEmailFetcher::class)->resolveRecipients('Lodge-A@Example.com');

        $this->assertTrue($result['partner']->is($partner));
        $this->assertTrue($result['listing']->is($listingA));
    }

    public function test_matches_by_partner_email_and_auto_sets_listing_when_partner_has_only_one(): void
    {
        $partner = Partner::create(['name' => 'Solo Lodge', 'email' => 'owner@example.com']);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        $result = app(PartnerEmailFetcher::class)->resolveRecipients('owner@example.com');

        $this->assertTrue($result['partner']->is($partner));
        $this->assertTrue($result['listing']->is($listing));
    }

    public function test_leaves_listing_null_when_partner_email_is_shared_across_multiple_listings(): void
    {
        $partner = Partner::create(['name' => 'Multi Lodge', 'email' => 'owner@example.com']);
        Listing::factory()->create(['partner_id' => $partner->id]);
        Listing::factory()->create(['partner_id' => $partner->id]);

        $result = app(PartnerEmailFetcher::class)->resolveRecipients('owner@example.com');

        $this->assertTrue($result['partner']->is($partner));
        $this->assertNull($result['listing']);
    }

    public function test_returns_no_match_for_an_unknown_sender(): void
    {
        $result = app(PartnerEmailFetcher::class)->resolveRecipients('nobody@example.com');

        $this->assertNull($result['partner']);
        $this->assertNull($result['listing']);
    }

    public function test_fetch_returns_zero_stats_when_pop3_is_not_configured(): void
    {
        config(['services.pop3.host' => null]);

        $stats = app(PartnerEmailFetcher::class)->fetch();

        $this->assertSame(0, $stats['fetched']);
    }
}
