<?php

namespace Tests\Feature;

use App\Services\Media\ThumbnailGenerator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThumbnailRouteTest extends TestCase
{
    /** A real JPEG of the given size, so GD has something genuine to work on. */
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 120, 60));
        ob_start();
        imagejpeg($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }

    private function dimensionsOf(string $binary): array
    {
        $info = getimagesizefromstring($binary);

        return [$info[0], $info[1]];
    }

    public function test_it_generates_a_webp_variant_and_redirects_to_it(): void
    {
        $disk = Storage::fake('r2');
        $disk->put('listings/lodge.jpg', $this->jpeg(1200, 900));

        $response = $this->get('/thumbs/400/listings/lodge.jpg');

        $response->assertRedirect();

        $variantKey = ThumbnailGenerator::variantKey('listings/lodge.jpg', 400);
        $disk->assertExists($variantKey);

        $variant = (string) $disk->get($variantKey);
        $this->assertSame([400, 300], $this->dimensionsOf($variant), 'aspect ratio preserved');
        $this->assertStringContainsString('WEBP', substr($variant, 0, 16));
        $this->assertLessThan(
            strlen((string) $disk->get('listings/lodge.jpg')),
            strlen($variant),
            'the point of the exercise',
        );
    }

    public function test_a_second_request_reuses_the_stored_variant(): void
    {
        $disk = Storage::fake('r2');
        $disk->put('listings/lodge.jpg', $this->jpeg(1200, 900));

        $this->get('/thumbs/400/listings/lodge.jpg')->assertRedirect();

        $variantKey = ThumbnailGenerator::variantKey('listings/lodge.jpg', 400);
        $disk->put($variantKey, 'SENTINEL');

        $this->get('/thumbs/400/listings/lodge.jpg')->assertRedirect();

        // Untouched: an existing variant is served, not regenerated.
        $this->assertSame('SENTINEL', $disk->get($variantKey));
    }

    public function test_it_never_upscales_a_small_original(): void
    {
        $disk = Storage::fake('r2');
        $disk->put('listings/tiny.jpg', $this->jpeg(120, 90));

        $this->get('/thumbs/800/listings/tiny.jpg')->assertRedirect();

        $variant = (string) $disk->get(ThumbnailGenerator::variantKey('listings/tiny.jpg', 800));

        $this->assertSame([120, 90], $this->dimensionsOf($variant));
    }

    public function test_only_widths_on_the_ladder_are_served(): void
    {
        $disk = Storage::fake('r2');
        $disk->put('listings/lodge.jpg', $this->jpeg(1200, 900));

        // Otherwise /thumbs/1/… through /thumbs/9999/… would let anyone fill
        // the bucket and burn CPU generating variants nothing renders.
        $this->get('/thumbs/377/listings/lodge.jpg')->assertNotFound();
        $this->get('/thumbs/abc/listings/lodge.jpg')->assertNotFound();

        $this->assertCount(1, $disk->allFiles(), 'nothing was generated');
    }

    public function test_it_refuses_traversal_and_recursion(): void
    {
        $disk = Storage::fake('r2');
        $disk->put('listings/lodge.jpg', $this->jpeg(200, 150));
        $disk->put('thumbs/400/listings/lodge.jpg.webp', 'existing');

        $this->get('/thumbs/400/../../etc/passwd')->assertNotFound();
        // A thumbnail of a thumbnail, which would otherwise nest forever.
        $this->get('/thumbs/400/thumbs/400/listings/lodge.jpg.webp')->assertNotFound();
    }

    public function test_a_missing_original_is_a_404(): void
    {
        Storage::fake('r2');

        $this->get('/thumbs/400/listings/nope.jpg')->assertNotFound();
    }

    public function test_an_unreadable_original_falls_back_to_itself(): void
    {
        $disk = Storage::fake('r2');
        $disk->put('listings/broken.jpg', 'this is not an image');

        // Still a redirect, pointing at the original: a thumbnail is an
        // optimisation, so failing to make one must not hide the photo.
        $response = $this->get('/thumbs/400/listings/broken.jpg');

        $response->assertRedirect();
        $this->assertStringContainsString('listings/broken.jpg', (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString('thumbs/', (string) $response->headers->get('Location'));
    }

    public function test_the_redirect_is_cacheable(): void
    {
        $disk = Storage::fake('r2');
        $disk->put('listings/lodge.jpg', $this->jpeg(400, 300));

        $response = $this->get('/thumbs/256/listings/lodge.jpg');

        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
    }
}
