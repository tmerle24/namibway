<?php

namespace Tests\Feature\Sites;

use App\Enums\ContentSource;
use App\Filament\Support\EditSiteImagesAction;
use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SiteImage;
use App\Models\SitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Putting pictures on a site.
 *
 * Until this existed the only thing that could create a `site_images` row was
 * generation, so a business with no listing had a content editor with an empty
 * picture list and no way to fill it — which made the editor useless for
 * roughly half the customers on the flyer.
 *
 * Two properties are worth more than the upload itself. A saved row is updated
 * rather than replaced, because the blocks point at these by id. And a picture
 * taken away is taken out of the blocks that used it, or a band would render a
 * gap where a photograph used to be.
 */
class SiteImageEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_picture_is_the_businesss_own_and_publishable(): void
    {
        $site = $this->site();

        $this->write($site, [['key' => 'sites/dune-edge/abc123-veranda.jpg', 'alt' => 'The veranda']]);

        $image = SiteImage::sole();

        $this->assertSame($site->id, $image->site_id);
        $this->assertSame('The veranda', $image->alt);
        $this->assertSame(0, $image->sort);

        // A Google Places photograph is `prospect_only` and blocks publication.
        // One somebody uploaded is neither.
        $this->assertSame(ContentSource::Partner, $image->content_source);
        $this->assertFalse($image->prospect_only);
    }

    /**
     * The blocks reference pictures by id, so a save that recreated rows would
     * quietly empty every band that used one.
     */
    public function test_an_existing_picture_keeps_its_id(): void
    {
        $site = $this->site();
        $image = $this->image($site, 'sites/dune-edge/one.jpg', sort: 0);

        $this->write($site, [
            ['id' => $image->id, 'key' => 'sites/dune-edge/one.jpg', 'alt' => 'Now described'],
        ]);

        $this->assertSame(1, SiteImage::count());
        $this->assertSame('Now described', $image->refresh()->alt);
    }

    public function test_order_follows_the_list(): void
    {
        $site = $this->site();
        $first = $this->image($site, 'sites/dune-edge/one.jpg', sort: 0);
        $second = $this->image($site, 'sites/dune-edge/two.jpg', sort: 1);

        $this->write($site, [
            ['id' => $second->id, 'key' => $second->key],
            ['id' => $first->id, 'key' => $first->key],
        ]);

        $this->assertSame(0, $second->refresh()->sort);
        $this->assertSame(1, $first->refresh()->sort);
    }

    public function test_a_removed_picture_is_taken_out_of_the_bands_that_used_it(): void
    {
        $site = $this->site();
        $kept = $this->image($site, 'sites/dune-edge/keep.jpg', sort: 0);
        $gone = $this->image($site, 'sites/dune-edge/gone.jpg', sort: 1);

        $page = SitePage::factory()->create(['site_id' => $site->id, 'is_home' => true, 'slug' => '']);

        $hero = SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'hero',
            'data' => ['headline' => 'Welcome', 'image_id' => $gone->id],
            'sort' => 0,
        ]);

        $gallery = SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'gallery',
            'data' => ['heading' => 'Pictures', 'image_ids' => [$kept->id, $gone->id]],
            'sort' => 1,
        ]);

        $this->write($site, [['id' => $kept->id, 'key' => $kept->key]]);

        $this->assertSame(1, SiteImage::count());
        $this->assertNull($hero->refresh()->data['image_id']);
        $this->assertSame([$kept->id], $gallery->refresh()->data['image_ids']);
    }

    /**
     * A row with no file is a repeater item somebody opened and did not fill.
     * It is not a picture and must not become an empty one.
     */
    public function test_a_row_with_no_file_is_ignored(): void
    {
        $site = $this->site();

        $this->write($site, [['key' => null, 'alt' => 'Nothing here'], ['key' => '', 'alt' => '']]);

        $this->assertSame(0, SiteImage::count());
    }

    private function site(): Site
    {
        $listing = Listing::factory()->create();

        return Site::factory()->create(['source_listing_id' => $listing->id, 'slug' => 'dune-edge']);
    }

    private function image(Site $site, string $key, int $sort): SiteImage
    {
        return SiteImage::create([
            'site_id' => $site->id,
            'key' => $key,
            'content_source' => ContentSource::Partner,
            'prospect_only' => false,
            'sort' => $sort,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     */
    private function write(Site $site, array $state): void
    {
        $method = new ReflectionMethod(EditSiteImagesAction::class, 'write');
        $method->setAccessible(true);
        $method->invoke(null, $site, $state);
    }
}
