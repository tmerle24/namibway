<?php

namespace Tests\Feature\Services;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
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

    private function emptyStats(): array
    {
        return ['fetched' => 0, 'matched' => 0, 'unmatched' => 0, 'skipped_duplicate' => 0, 'rows' => []];
    }

    public function test_processes_a_raw_rfc822_message_into_an_inbound_partner_message(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        $raw = implode("\r\n", [
            'From: Owner <owner@example.com>',
            'Subject: Re: About your listing',
            'Date: Mon, 02 Aug 2026 10:00:00 +0200',
            'Message-ID: <abc123@example.com>',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Sounds good, thanks!',
        ]);

        $stats = $this->emptyStats();

        app(PartnerEmailFetcher::class)->processMessage($raw, false, $stats);

        $this->assertSame(1, $stats['matched']);
        $this->assertDatabaseHas('partner_messages', [
            'partner_id' => $partner->id,
            'listing_id' => $listing->id,
            'direction' => PartnerMessage::DIRECTION_INBOUND,
            'source_uid' => 'abc123@example.com',
            'subject' => 'Re: About your listing',
        ]);
        $this->assertStringContainsString('Sounds good, thanks!', PartnerMessage::sole()->body);
    }

    public function test_a_message_id_already_ingested_is_not_stored_again(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);
        Listing::factory()->create(['partner_id' => $partner->id]);

        $raw = implode("\r\n", [
            'From: owner@example.com',
            'Subject: Hi',
            'Message-ID: <dup-1@example.com>',
            'Content-Type: text/plain',
            '',
            'Hello',
        ]);

        $fetcher = app(PartnerEmailFetcher::class);

        $stats = $this->emptyStats();
        $fetcher->processMessage($raw, false, $stats);

        $stats = $this->emptyStats();
        $fetcher->processMessage($raw, false, $stats);

        $this->assertSame(1, $stats['skipped_duplicate']);
        $this->assertSame(1, PartnerMessage::count());
    }

    public function test_unmatched_sender_is_not_stored(): void
    {
        $raw = implode("\r\n", [
            'From: stranger@example.com',
            'Subject: Hi',
            'Message-ID: <no-match@example.com>',
            'Content-Type: text/plain',
            '',
            'Hello',
        ]);

        $stats = $this->emptyStats();

        app(PartnerEmailFetcher::class)->processMessage($raw, false, $stats);

        $this->assertSame(1, $stats['unmatched']);
        $this->assertSame(0, PartnerMessage::count());
    }
}
