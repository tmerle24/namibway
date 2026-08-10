<?php

namespace Tests\Feature\Jobs;

use App\Enums\ContentSource;
use App\Jobs\CrawlListingWebsiteJob;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The self-improving half of the content ladder: a listing that entered the
 * system as a directory record should stop looking like one as soon as its own
 * website is crawled.
 */
class CrawlListingWebsiteUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');

        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            'https://lodge.example/*' => Http::response($this->homepage(), 200, ['Content-Type' => 'text/html']),
            'https://lodge.example' => Http::response($this->homepage(), 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('binary-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_the_owners_own_text_replaces_directory_prose(): void
    {
        $listing = Listing::factory()->create([
            'website' => 'https://lodge.example',
            'description' => 'Text lifted from a directory listing.',
            'description_source' => ContentSource::Directory,
        ]);

        CrawlListingWebsiteJob::dispatchSync($listing->id);
        $listing->refresh();

        $this->assertSame('Eight rooms above the riverbed, run by the family who built it.', $listing->description);
        $this->assertSame(ContentSource::WebsiteScrape, $listing->description_source);
    }

    /**
     * A re-crawl must not trade our own copy for the site's meta tag. The
     * og:description is frequently a 160-character SEO blurb, and our copy was
     * generated from the listing's facts with this same site as context.
     */
    public function test_text_we_wrote_ourselves_is_left_alone(): void
    {
        $listing = Listing::factory()->create([
            'website' => 'https://lodge.example',
            'description' => 'Our own copy, written from the facts.',
            'description_source' => ContentSource::AiGenerated,
        ]);

        CrawlListingWebsiteJob::dispatchSync($listing->id);

        $this->assertSame('Our own copy, written from the facts.', $listing->refresh()->description);
    }

    public function test_a_hand_written_description_is_left_alone_too(): void
    {
        $listing = Listing::factory()->create([
            'website' => 'https://lodge.example',
            'description' => 'What an actual person wrote about this lodge.',
            'description_source' => ContentSource::Manual,
        ]);

        CrawlListingWebsiteJob::dispatchSync($listing->id);

        $this->assertSame('What an actual person wrote about this lodge.', $listing->refresh()->description);
    }

    public function test_a_directory_photo_on_the_live_slot_is_upgraded_but_only_into_the_approval_queue(): void
    {
        $listing = Listing::factory()->create([
            'website' => 'https://lodge.example',
            'image' => 'listings/x/namibweb-hero.jpg',
            'photos_source' => ContentSource::Directory,
            'pending_image' => null,
        ]);

        CrawlListingWebsiteJob::dispatchSync($listing->id);
        $listing->refresh();

        // Still the owner's copyright, so it waits for approval rather than
        // going live — but it is now approvable, which the directory photo
        // never was.
        $this->assertSame('listings/x/namibweb-hero.jpg', $listing->image);
        $this->assertNotNull($listing->pending_image);
        $this->assertSame(ContentSource::WebsiteScrape, $listing->pending_photos_source);
        $this->assertTrue($listing->hasApprovablePhotos());
    }

    public function test_a_listing_with_no_photo_at_all_still_gets_one_published_directly(): void
    {
        $listing = Listing::factory()->create([
            'website' => 'https://lodge.example',
            'image' => null,
            'photos_source' => null,
        ]);

        CrawlListingWebsiteJob::dispatchSync($listing->id);
        $listing->refresh();

        $this->assertNotNull($listing->image);
        $this->assertSame(ContentSource::WebsiteScrape, $listing->photos_source);
    }

    private function homepage(): string
    {
        return <<<'HTML'
        <html><head>
        <meta property="og:description" content="Eight rooms above the riverbed, run by the family who built it.">
        <meta property="og:image" content="https://lodge.example/img/hero.jpg">
        </head><body>
        <img src="/img/hero.jpg" width="1200" height="800">
        <img src="/img/room.jpg" width="1200" height="800">
        </body></html>
        HTML;
    }
}
