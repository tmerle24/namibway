<?php

namespace App\Http\Controllers;

use App\Services\Media\ThumbnailGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves `/thumbs/{width}/{path}` — a width-limited copy of an image stored on
 * R2, made on first request and kept in the bucket afterwards.
 *
 * The response is a redirect, never the image bytes: the photo then travels
 * straight from R2 to the browser instead of through PHP-FPM, which would tie
 * up one worker per image for as long as the transfer takes (a page of 20
 * thumbnails would occupy 20 of them). The cost is one small extra round trip,
 * which the long cache header removes for returning visitors.
 *
 * A miss is never fatal — anything unreadable, oversized or unresizable falls
 * back to the original URL. Slower than intended, but the picture still shows.
 */
class ThumbnailController extends Controller
{
    public function __invoke(string $width, string $path, ThumbnailGenerator $generator): RedirectResponse
    {
        $requestedWidth = $this->validWidth($width);
        $sourceKey = $this->validSourceKey($path);

        $disk = Storage::disk('r2');

        if (! $disk->exists($sourceKey)) {
            throw new NotFoundHttpException;
        }

        $variantKey = $generator->ensure($sourceKey, $requestedWidth);

        return $this->redirectToObject($variantKey ?? $sourceKey);
    }

    /**
     * Only widths on the configured ladder are served. Without this, anyone
     * could walk /thumbs/1/... to /thumbs/9999/... and have the server generate
     * and store an unbounded number of variants — an easy way to burn CPU and
     * fill the bucket.
     */
    private function validWidth(string $width): int
    {
        /** @var list<int> $ladder */
        $ladder = config('media.transforms.widths', []);

        if (! ctype_digit($width) || ! in_array((int) $width, $ladder, true)) {
            throw new NotFoundHttpException;
        }

        return (int) $width;
    }

    /**
     * The path segment is a bucket key supplied by the client, so it is treated
     * as untrusted: no traversal, and no reaching into the derivative prefix
     * (which would let a thumbnail be made of a thumbnail, recursively).
     */
    private function validSourceKey(string $path): string
    {
        $key = ltrim($path, '/');

        if ($key === ''
            || str_contains($key, '..')
            || str_starts_with($key, ThumbnailGenerator::PREFIX.'/')) {
            throw new NotFoundHttpException;
        }

        return $key;
    }

    private function redirectToObject(string $key): RedirectResponse
    {
        // Immutable because the key is derived from the source key and width:
        // a different image, or a different size, is a different URL. Browsers
        // that honour this skip the redirect entirely on later visits.
        return redirect()->away(Storage::disk('r2')->url($key))
            ->header('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
