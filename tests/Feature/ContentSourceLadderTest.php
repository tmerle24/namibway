<?php

namespace Tests\Feature;

use App\Enums\ContentSource;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rules that decide what a listing may publish, and what replaces what.
 *
 * @see \App\Enums\ContentSource
 */
class ContentSourceLadderTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_directory_content_is_unpublishable(): void
    {
        $this->assertFalse(ContentSource::Directory->publishable());

        foreach ([
            ContentSource::Partner,
            ContentSource::Manual,
            ContentSource::WebsiteScrape,
            ContentSource::AiGenerated,
            ContentSource::GooglePlaces,
        ] as $source) {
            $this->assertTrue($source->publishable(), $source->value.' should be publishable');
        }
    }

    public function test_the_ladder_orders_owner_material_above_everything_and_directories_below(): void
    {
        $this->assertTrue(ContentSource::WebsiteScrape->outranks(ContentSource::Directory));
        $this->assertTrue(ContentSource::AiGenerated->outranks(ContentSource::Directory));
        $this->assertTrue(ContentSource::AiGenerated->outranks(ContentSource::GooglePlaces));
        $this->assertTrue(ContentSource::Partner->outranks(ContentSource::WebsiteScrape));

        $this->assertFalse(ContentSource::Directory->outranks(ContentSource::GooglePlaces));
        $this->assertFalse(ContentSource::GooglePlaces->outranks(ContentSource::WebsiteScrape));
        $this->assertFalse(ContentSource::WebsiteScrape->outranks(ContentSource::Partner));
    }

    public function test_nothing_outranks_content_of_unknown_provenance(): void
    {
        // A value someone put there before sources were tracked is not ours to
        // overwrite — callers handle the genuinely empty case themselves.
        $this->assertFalse(ContentSource::Partner->outranks(null));
        $this->assertFalse(ContentSource::WebsiteScrape->outranks(null));
    }

    public function test_a_source_does_not_outrank_itself(): void
    {
        foreach (ContentSource::cases() as $source) {
            $this->assertFalse($source->outranks($source), $source->value.' should not outrank itself');
        }
    }

    public function test_approving_publishes_website_photos_and_records_where_they_came_from(): void
    {
        $listing = Listing::factory()->create([
            'image' => null,
            'gallery' => [],
            'pending_image' => 'listings/x/hero.jpg',
            'pending_gallery' => ['listings/x/1.jpg', 'listings/x/2.jpg'],
            'pending_photos_source' => ContentSource::WebsiteScrape,
        ]);

        $listing->approvePendingPhotos();
        $listing->refresh();

        $this->assertSame('listings/x/hero.jpg', $listing->image);
        $this->assertCount(2, $listing->gallery);
        $this->assertSame(ContentSource::WebsiteScrape, $listing->photos_source);
        $this->assertNull($listing->pending_image);
        $this->assertNull($listing->pending_photos_source);
        $this->assertNotNull($listing->photos_approved_at);
    }

    /**
     * The load-bearing one: an owner can consent to their own photos being
     * published, but nobody on this side can license a directory's photography.
     */
    public function test_approving_refuses_to_publish_directory_photos(): void
    {
        $listing = Listing::factory()->create([
            'image' => null,
            'gallery' => [],
            'pending_image' => 'listings/x/namibweb-hero.jpg',
            'pending_gallery' => ['listings/x/namibweb-1.jpg'],
            'pending_photos_source' => ContentSource::Directory,
        ]);

        $listing->approvePendingPhotos();
        $listing->refresh();

        $this->assertNull($listing->image);
        $this->assertEmpty($listing->gallery);
        $this->assertNull($listing->photos_approved_at);
        // Still staged: useful internally, just never public.
        $this->assertSame('listings/x/namibweb-hero.jpg', $listing->pending_image);
        $this->assertFalse($listing->hasApprovablePhotos());
        $this->assertTrue($listing->hasPendingPhotos());
    }

    public function test_staged_photos_of_unknown_provenance_stay_approvable(): void
    {
        // Rows staged before pending_photos_source existed came from the website
        // crawler — the only thing that staged photos then.
        $listing = Listing::factory()->create([
            'image' => null,
            'pending_image' => 'listings/x/hero.jpg',
            'pending_photos_source' => null,
        ]);

        $listing->approvePendingPhotos();

        $this->assertSame('listings/x/hero.jpg', $listing->refresh()->image);
        $this->assertSame(ContentSource::WebsiteScrape, $listing->photos_source);
    }

    public function test_the_public_page_offers_no_approve_action_for_reference_photos(): void
    {
        $listing = Listing::factory()->create([
            'is_published' => true,
            'pending_image' => 'listings/x/namibweb-hero.jpg',
            'pending_photos_source' => ContentSource::Directory,
        ]);

        $this->actingAs($this->adminUser())
            ->get("/listings/{$listing->slug}")
            ->assertInertia(fn ($page) => $page
                ->where('can_approve_photos', false)
                // …but an admin still sees them, as reference.
                ->where('listing.pending_photos_source', 'directory')
                ->whereNot('listing.pending_image', null)
            );
    }

    private function adminUser(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['is_admin' => true]);
    }
}
