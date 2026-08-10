<?php

namespace Tests\Feature;

use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingWebsiteCrawlQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_changed_website_marks_the_listing_due_for_a_crawl(): void
    {
        $listing = Listing::factory()->create([
            'website' => 'https://old-lodge.com',
            'og_scraped_at' => now()->subDay(),
        ]);

        $listing->update(['website' => 'https://new-lodge.com']);

        // A different site has never been crawled — the old timestamp would
        // otherwise park it for the rest of the refresh window.
        $this->assertNull($listing->refresh()->og_scraped_at);
    }

    public function test_saving_a_listing_without_touching_the_website_leaves_the_crawl_state_alone(): void
    {
        $crawledAt = now()->subDay();

        $listing = Listing::factory()->create([
            'website' => 'https://lodge.com',
            'og_scraped_at' => $crawledAt,
        ]);

        $listing->update(['phone' => '+264 61 123 456']);

        $this->assertSame(
            $crawledAt->timestamp,
            $listing->refresh()->og_scraped_at->timestamp
        );
    }

    public function test_the_sweep_picks_up_a_listing_whose_website_was_just_added(): void
    {
        $stale = Listing::factory()->create([
            'website' => 'https://crawled-recently.com',
            'og_scraped_at' => now()->subHours(2),
        ]);

        $fresh = Listing::factory()->create(['website' => null, 'og_scraped_at' => null]);
        $fresh->update(['website' => 'https://just-added.com']);

        $this->artisan('namibway:scrape-websites', ['--limit' => 1, '--dry-run' => true])
            ->expectsOutputToContain('just-added.com')
            ->assertSuccessful();

        $this->assertNotNull($stale->refresh()->og_scraped_at);
    }
}
