<?php

namespace Tests\Feature\Sites;

use App\Enums\ContentSource;
use App\Models\Listing;
use App\Sites\Generation\SiteGenerator;
use App\Sites\Publishing\CannotPublish;
use App\Sites\Publishing\PublishGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The hard rule, on the one product where getting it wrong is publication under
 * somebody else's name.
 *
 * A directory's prose and photography stay internal no matter how the pipeline
 * is wired — editing them does not make them ours. And a picture that may be
 * shown on namibway.com is not automatically a picture we may hand a customer
 * who has been promised they keep their content.
 */
class SiteContentLadderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
    }

    private function listingWithPhoto(array $attributes = []): Listing
    {
        Storage::disk('r2')->put('listings/source/hero.jpg', 'bytes');

        return Listing::factory()->create(array_merge([
            'name' => 'Desert Rest Camp',
            'description' => 'A camp at the edge of the dunes.',
            'image' => 'listings/source/hero.jpg',
        ], $attributes));
    }

    public function test_directory_text_is_never_imported(): void
    {
        $listing = $this->listingWithPhoto([
            'description_source' => ContentSource::Directory,
            'photos_source' => ContentSource::Partner,
        ]);

        $generator = new SiteGenerator;
        $site = $generator->fromListing($listing);

        $about = $site->pages()->first()->blocks()->where('type', 'about')->first();

        $this->assertNull($about->data['body']);
        $this->assertNotEmpty($generator->report()->skipped);
    }

    public function test_directory_photographs_are_never_imported(): void
    {
        $listing = $this->listingWithPhoto([
            'description_source' => ContentSource::Partner,
            'photos_source' => ContentSource::Directory,
        ]);

        $site = (new SiteGenerator)->fromListing($listing);

        $this->assertSame(0, $site->images()->count());
    }

    public function test_a_partners_own_photograph_is_copied_into_the_sites_own_prefix(): void
    {
        $listing = $this->listingWithPhoto(['photos_source' => ContentSource::Partner]);

        $site = (new SiteGenerator)->fromListing($listing);
        $image = $site->images()->first();

        $this->assertNotNull($image);
        $this->assertStringStartsWith('sites/'.$site->slug.'/', $image->key);
        Storage::disk('r2')->assertExists($image->key);

        // Copied, not moved: the listing must be untouched, and the site must
        // not depend on it.
        Storage::disk('r2')->assertExists('listings/source/hero.jpg');
        $this->assertFalse($image->prospect_only);
    }

    public function test_a_stock_placeholder_is_not_passed_off_as_the_businesss_own(): void
    {
        $listing = Listing::factory()->create([
            'image' => 'https://images.unsplash.com/photo-123?w=1200',
            'photos_source' => ContentSource::Partner,
        ]);

        $generator = new SiteGenerator;
        $site = $generator->fromListing($listing);

        $this->assertSame(0, $site->images()->count());
        $this->assertContains(
            'photo — stock placeholder, not the business\'s own photograph',
            array_map(fn (array $row) => $row['field'].' — '.$row['reason'], $generator->report()->skipped),
        );
    }

    public function test_google_photographs_are_for_prospecting_and_block_publication(): void
    {
        $listing = $this->listingWithPhoto(['photos_source' => ContentSource::GooglePlaces]);

        $site = (new SiteGenerator)->fromListing($listing);
        $image = $site->images()->first();

        // Good enough to win a meeting with — publishable on namibway.com under
        // Google's terms — and never good enough to hand to a customer.
        $this->assertNotNull($image);
        $this->assertTrue($image->prospect_only);

        $gate = new PublishGate;
        $this->assertNotEmpty($gate->blockers($site));

        $this->expectException(CannotPublish::class);
        $gate->publish($site->refresh());
    }

    public function test_publishing_succeeds_and_clears_unused_prospect_images(): void
    {
        $listing = $this->listingWithPhoto(['photos_source' => ContentSource::GooglePlaces]);

        $site = (new SiteGenerator)->fromListing($listing);
        $image = $site->images()->first();

        // The agency replaces the hero, so nothing points at Google's picture
        // any more. Publication then goes through, and the copy is deleted
        // rather than left in a bucket.
        $hero = $site->pages()->first()->blocks()->where('type', 'hero')->first();
        $hero->update(['data' => ['image_id' => null] + $hero->data]);

        (new PublishGate)->publish($site->refresh());

        $this->assertTrue($site->refresh()->isPublished());
        $this->assertSame(0, $site->images()->count());
        Storage::disk('r2')->assertMissing($image->key);
    }

    public function test_staged_photographs_awaiting_approval_are_not_imported(): void
    {
        Storage::disk('r2')->put('listings/source/pending.jpg', 'bytes');

        $listing = Listing::factory()->create([
            'image' => null,
            'pending_image' => 'listings/source/pending.jpg',
            'pending_photos_source' => ContentSource::WebsiteScrape,
        ]);

        $site = (new SiteGenerator)->fromListing($listing);

        // A website is a considerably more public place to publish an
        // unapproved photograph than the review queue it is sitting in.
        $this->assertSame(0, $site->images()->count());
    }
}
