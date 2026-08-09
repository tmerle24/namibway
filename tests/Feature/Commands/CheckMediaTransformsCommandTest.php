<?php

namespace Tests\Feature\Commands;

use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The command's job is to tell the truth about a production switch-on, so what
 * matters is that it fails loudly on the two ways this can look "fine" but not
 * be: a transformed URL that 404s, and one that returns 200 but hands back the
 * untouched original.
 */
class CheckMediaTransformsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function configureOrigin(bool $enabled = true): void
    {
        config([
            'media.transforms.enabled' => $enabled,
            'media.transforms.origins' => ['https://cdn.namibway.test'],
            'media.transforms.widths' => [64, 128, 256, 400, 800, 1600],
            'media.transforms.quality' => 80,
        ]);
    }

    /** Fakes a big original and a small resized variant, keyed on the transform path. */
    private function fakeResizing(int $originalBytes = 400_000, int $resizedBytes = 20_000): void
    {
        Http::fake(function (Request $request) use ($originalBytes, $resizedBytes) {
            $isTransform = str_contains($request->url(), '/cdn-cgi/image/');

            return Http::response(
                str_repeat('x', $isTransform ? $resizedBytes : $originalBytes),
                200,
                ['Content-Type' => 'image/webp'],
            );
        });
    }

    public function test_it_passes_when_the_transformed_url_comes_back_smaller(): void
    {
        $this->configureOrigin();
        $this->fakeResizing();

        $this->artisan('namibway:check-media-transforms', [
            '--url' => 'https://cdn.namibway.test/listings/lodge.jpg',
        ])
            ->assertSuccessful();
    }

    public function test_it_fails_when_the_transformed_url_does_not_resolve(): void
    {
        $this->configureOrigin();

        // The custom domain is attached but Transformations are off for the
        // zone: /cdn-cgi/image/ is just a path that doesn't exist.
        Http::fake(function (Request $request) {
            return str_contains($request->url(), '/cdn-cgi/image/')
                ? Http::response('not found', 404)
                : Http::response(str_repeat('x', 400_000), 200, ['Content-Type' => 'image/jpeg']);
        });

        $this->artisan('namibway:check-media-transforms', [
            '--url' => 'https://cdn.namibway.test/listings/lodge.jpg',
        ])
            ->assertFailed();
    }

    public function test_it_fails_when_the_transform_passes_the_original_through(): void
    {
        $this->configureOrigin();

        // 200 on both, identical size — Cloudflare served the original. This is
        // the failure mode a plain status check would call a success.
        $this->fakeResizing(originalBytes: 400_000, resizedBytes: 400_000);

        $this->artisan('namibway:check-media-transforms', [
            '--url' => 'https://cdn.namibway.test/listings/lodge.jpg',
        ])
            ->assertFailed();
    }

    public function test_it_fails_when_the_url_is_not_on_a_configured_origin(): void
    {
        $this->configureOrigin();
        $this->fakeResizing();

        // A scraped operator's own site is never rewritten, so there is nothing
        // to probe and the command must not report success.
        $this->artisan('namibway:check-media-transforms', [
            '--url' => 'https://some-lodge.com.na/hero.jpg',
        ])
            ->assertFailed();
    }

    public function test_it_still_probes_while_the_feature_flag_is_off(): void
    {
        // The whole point of the pre-flight: confirm Cloudflare works before
        // flipping the flag in production.
        $this->configureOrigin(enabled: false);
        $this->fakeResizing();

        $this->artisan('namibway:check-media-transforms', [
            '--url' => 'https://cdn.namibway.test/listings/lodge.jpg',
        ])
            ->expectsOutputToContain('MEDIA_TRANSFORMS_ENABLED is still false')
            ->assertSuccessful();
    }

    public function test_it_reports_images_stranded_on_a_stale_origin(): void
    {
        $this->configureOrigin();
        $this->fakeResizing();
        Storage::fake('r2');

        // Downloaded back when CLOUDFLARE_R2_URL was still the r2.dev URL, so
        // the row carries that host even though the bucket now answers on the
        // custom domain. The object itself is in the bucket — that's what tells
        // this apart from a genuinely foreign URL.
        Storage::disk('r2')->put('listings/google-places/lodge-abc.jpg', 'photo');

        Listing::factory()->create([
            'image' => 'https://pub-oldhash.r2.dev/listings/google-places/lodge-abc.jpg',
            'is_published' => true,
        ]);

        $this->artisan('namibway:check-media-transforms', [
            '--url' => 'https://cdn.namibway.test/listings/lodge.jpg',
        ])
            ->expectsOutputToContain('1 listing image(s) are stored as an absolute URL');
    }

    public function test_a_foreign_url_is_not_mistaken_for_a_stale_origin(): void
    {
        $this->configureOrigin();
        $this->fakeResizing();
        Storage::fake('r2');

        // Scraped from the operator's own site: not on our origin, and the path
        // is not in our bucket either, so it must not be counted as stranded.
        Listing::factory()->create([
            'image' => 'https://some-lodge.com.na/wp-content/hero.jpg',
            'is_published' => true,
        ]);

        $this->artisan('namibway:check-media-transforms', [
            '--url' => 'https://cdn.namibway.test/listings/lodge.jpg',
        ])
            ->expectsOutputToContain('every bucket-hosted image sits on a configured origin')
            ->assertSuccessful();
    }
}
