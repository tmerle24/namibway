<?php

namespace Tests\Feature;

use App\Enums\ContentSource;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The rules that decide what a listing may publish, and what replaces what.
 *
 * @see ContentSource
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

    /**
     * Anything that edits a description through a human-facing path — the admin
     * panel, the partner panel, the Excel importer — claims the top tier, so
     * generated copy can never overwrite someone's actual writing.
     */
    public function test_a_hand_edited_description_claims_the_top_tier(): void
    {
        $listing = Listing::factory()->create([
            'description' => 'Text lifted from a directory listing.',
            'description_source' => ContentSource::Directory,
        ]);

        $listing->update(['description' => 'What an actual person wrote about this lodge.']);

        $this->assertSame(ContentSource::Manual, $listing->refresh()->description_source);
        $this->assertFalse(ContentSource::AiGenerated->outranks($listing->description_source));
        $this->assertFalse(ContentSource::WebsiteScrape->outranks($listing->description_source));
    }

    public function test_a_writer_that_declares_its_own_source_keeps_it(): void
    {
        $listing = Listing::factory()->create(['description_source' => null]);

        $listing->update([
            'description' => 'Copy generated from the listing facts.',
            'description_source' => ContentSource::AiGenerated,
        ]);

        $this->assertSame(ContentSource::AiGenerated, $listing->refresh()->description_source);
    }

    public function test_the_public_page_offers_no_approve_action_for_reference_photos(): void
    {
        $listing = Listing::factory()->create([
            'is_published' => true,
            'pending_image' => 'listings/x/namibweb-hero.jpg',
            'pending_photos_source' => ContentSource::Directory,
        ]);

        // Viewing a listing queues an enrichment run when one is due, which on
        // the sync test queue would crawl the open internet.
        Queue::fake();

        $this->actingAs($this->admin())
            ->get("/listings/{$listing->slug}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_approve_photos', false)
                // …but an admin still sees them, as reference.
                ->where('listing.pending_photos_source', 'directory')
                ->whereNot('listing.pending_image', null)
            );
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
