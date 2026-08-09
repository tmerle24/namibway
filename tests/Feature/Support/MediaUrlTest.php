<?php

namespace Tests\Feature\Support;

use App\Support\MediaUrl;
use Tests\TestCase;

/**
 * Guards the two properties that make the thumbnail rewrite safe to leave on:
 * it never touches a URL we don't serve, and it never changes what an <img>
 * points at when transforms are disabled (which is the production default until
 * a custom domain is attached to the media bucket — see config/media.php).
 */
class MediaUrlTest extends TestCase
{
    private function enableTransforms(): void
    {
        config([
            'media.transforms.enabled' => true,
            'media.transforms.origins' => ['https://cdn.namibway.test'],
            'media.transforms.widths' => [64, 128, 256, 400, 800, 1600],
            'media.transforms.quality' => 80,
        ]);
    }

    public function test_it_rewrites_a_bucket_url_through_cloudflare(): void
    {
        $this->enableTransforms();

        $this->assertSame(
            'https://cdn.namibway.test/cdn-cgi/image/format=auto,fit=scale-down,width=64,quality=80/listings/lodge.jpg',
            MediaUrl::thumb('https://cdn.namibway.test/listings/lodge.jpg', 44),
        );
    }

    public function test_it_leaves_root_relative_urls_alone(): void
    {
        $this->enableTransforms();

        // Root-relative means app origin, and the app origin is served
        // directly by nginx, not through Cloudflare — /cdn-cgi/image/ does not
        // exist there. Wrapping these 404'd every bundled fallback image and
        // every legacy /storage upload in production on 2026-08-09.
        $this->assertSame(
            '/images/explore/activity-1.jpg',
            MediaUrl::thumb('/images/explore/activity-1.jpg', 400),
        );

        $this->assertSame(
            '/storage/regions/fish-river-canyon.jpg',
            MediaUrl::thumb('/storage/regions/fish-river-canyon.jpg', 400),
        );
    }

    public function test_it_leaves_foreign_urls_alone(): void
    {
        $this->enableTransforms();

        // A scraped operator's own website is not ours to proxy — rewriting it
        // onto our zone would just 404.
        $url = 'https://some-lodge.com.na/wp-content/hero.jpg';

        $this->assertSame($url, MediaUrl::thumb($url, 44));
    }

    public function test_it_is_inert_while_transforms_are_disabled(): void
    {
        config(['media.transforms.enabled' => false]);

        $url = 'https://cdn.namibway.test/listings/lodge.jpg';

        $this->assertSame($url, MediaUrl::thumb($url, 44));
    }

    public function test_it_does_not_nest_an_already_transformed_url(): void
    {
        $this->enableTransforms();

        $once = MediaUrl::thumb('https://cdn.namibway.test/listings/lodge.jpg', 44);

        $this->assertSame($once, MediaUrl::thumb($once, 44));
    }

    public function test_it_resizes_unsplash_even_while_transforms_are_disabled(): void
    {
        config(['media.transforms.enabled' => false, 'media.transforms.widths' => [64, 128, 256, 400, 800, 1600]]);

        $resized = MediaUrl::thumb(
            'https://images.unsplash.com/photo-123?w=1200&q=80&auto=format&fit=crop',
            44,
        );

        // Unsplash resizes off its own query string, so this costs nothing and
        // needs no Cloudflare involvement.
        $this->assertStringContainsString('w=64', (string) $resized);
        $this->assertStringNotContainsString('w=1200', (string) $resized);
        $this->assertStringContainsString('q=80', (string) $resized);
    }

    public function test_it_snaps_widths_up_to_the_ladder(): void
    {
        $this->enableTransforms();

        // The 44px day thumbnail, the 48px swap row and the 52px stay card all
        // share one cached variant instead of billing three.
        $this->assertSame(64, MediaUrl::snapWidth(44));
        $this->assertSame(64, MediaUrl::snapWidth(48));
        $this->assertSame(64, MediaUrl::snapWidth(52));
        $this->assertSame(400, MediaUrl::snapWidth(400));
        $this->assertSame(1600, MediaUrl::snapWidth(4000));
    }

    public function test_srcset_covers_retina_and_is_omitted_when_pointless(): void
    {
        $this->enableTransforms();

        $srcset = MediaUrl::srcset('https://cdn.namibway.test/listings/lodge.jpg', 44);

        $this->assertStringContainsString('width=64', (string) $srcset);
        $this->assertStringContainsString('width=128', (string) $srcset);
        $this->assertStringEndsWith(' 2x', (string) $srcset);

        // Nothing to offer for an image we can't resize.
        $this->assertNull(MediaUrl::srcset('https://some-lodge.com.na/hero.jpg', 44));
    }

    public function test_null_in_null_out(): void
    {
        $this->enableTransforms();

        $this->assertNull(MediaUrl::thumb(null, 44));
        $this->assertNull(MediaUrl::thumb('', 44));
    }
}
