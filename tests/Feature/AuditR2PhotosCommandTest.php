<?php

namespace Tests\Feature;

use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditR2PhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_classifies_files_and_only_deletes_orphans_when_asked(): void
    {
        $disk = Storage::fake('r2');

        $disk->put('listings/website-crawl/published.jpg', 'x');
        $disk->put('listings/website-crawl/unpublished.jpg', 'x');
        $disk->put('listings/website-crawl/pending.jpg', 'x');
        $disk->put('listings/website-crawl/orphan.jpg', 'x');

        Listing::factory()->create([
            'is_published' => true,
            'image' => $disk->url('listings/website-crawl/published.jpg'),
        ]);
        Listing::factory()->create([
            'is_published' => false,
            'image' => null,
            'gallery' => [$disk->url('listings/website-crawl/unpublished.jpg')],
        ]);
        Listing::factory()->create([
            'is_published' => false,
            'image' => null,
            'pending_image' => $disk->url('listings/website-crawl/pending.jpg'),
        ]);

        // Dry run: nothing gets deleted, even though "orphan.jpg" isn't referenced anywhere.
        $this->artisan('photos:audit-r2', ['--prefix' => ['listings/website-crawl']])
            ->assertSuccessful();

        $disk->assertExists('listings/website-crawl/published.jpg');
        $disk->assertExists('listings/website-crawl/unpublished.jpg');
        $disk->assertExists('listings/website-crawl/pending.jpg');
        $disk->assertExists('listings/website-crawl/orphan.jpg');

        // With --delete-orphaned: only the truly unreferenced file goes.
        $this->artisan('photos:audit-r2', [
            '--prefix' => ['listings/website-crawl'],
            '--delete-orphaned' => true,
        ])
            ->expectsConfirmation('1 orphaned file(s) under listings/website-crawl will be permanently deleted. Continue?', 'yes')
            ->assertSuccessful();

        $disk->assertExists('listings/website-crawl/published.jpg');
        $disk->assertExists('listings/website-crawl/unpublished.jpg');
        $disk->assertExists('listings/website-crawl/pending.jpg');
        $disk->assertMissing('listings/website-crawl/orphan.jpg');
    }

    /**
     * The dangerous case. Scraper photos are stored as absolute URLs built from
     * CLOUDFLARE_R2_URL at download time, so a later change to that setting
     * leaves every stored URL pointing at the previous host. Matching on URLs
     * would then classify the entire referenced library as orphaned — and
     * --delete-orphaned would delete it.
     */
    public function test_a_changed_bucket_url_does_not_turn_live_photos_into_orphans(): void
    {
        $disk = Storage::fake('r2');

        $disk->put('listings/google-places/live.jpg', 'x');
        $disk->put('listings/google-places/orphan.jpg', 'x');

        // Downloaded while the bucket answered on its r2.dev address...
        Listing::factory()->create([
            'is_published' => true,
            'image' => 'https://pub-oldhash.r2.dev/listings/google-places/live.jpg',
        ]);

        // ...and today it answers on a custom domain.
        config(['filesystems.disks.r2.url' => 'https://cdn.namibway.test']);

        $this->artisan('photos:audit-r2', [
            '--prefix' => ['listings/google-places'],
            '--delete-orphaned' => true,
        ])
            ->expectsConfirmation('1 orphaned file(s) under listings/google-places will be permanently deleted. Continue?', 'yes')
            ->assertSuccessful();

        $disk->assertExists('listings/google-places/live.jpg');
        $disk->assertMissing('listings/google-places/orphan.jpg');
    }

    public function test_it_matches_relative_paths_too(): void
    {
        $disk = Storage::fake('r2');

        $disk->put('listings/hero.jpg', 'x');
        $disk->put('listings/nobody.jpg', 'x');

        // Filament uploads store a path relative to the disk, not a URL.
        Listing::factory()->create(['is_published' => true, 'image' => 'listings/hero.jpg']);

        $this->artisan('photos:audit-r2', [
            '--prefix' => ['listings'],
            '--delete-orphaned' => true,
        ])
            ->expectsConfirmation('1 orphaned file(s) under listings will be permanently deleted. Continue?', 'yes')
            ->assertSuccessful();

        $disk->assertExists('listings/hero.jpg');
        $disk->assertMissing('listings/nobody.jpg');
    }
}
