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
}
