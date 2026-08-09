<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Produces a width-limited WebP copy of an image already stored on R2, and
 * keeps it next to the original in the bucket.
 *
 * Derivatives are a cache, not data: the original is the only truth, so a
 * variant can be deleted at any time (or the whole `thumbs/` prefix wiped when
 * the width ladder changes) and it simply gets made again on the next request.
 * That is why nothing pre-generates and there is no backfill to run.
 *
 * Everything here fails soft — a corrupt source, an unreadable format, an image
 * too large to decode safely. The caller falls back to serving the original,
 * which is slow but correct; a thumbnail is an optimisation, never a
 * precondition for showing a photo.
 */
class ThumbnailGenerator
{
    /**
     * Refuse to decode anything beyond this many pixels. GD holds roughly
     * 4 bytes per pixel while resizing, so 25 MP is about 100 MB of memory —
     * already generous for a library whose originals top out at 2000px, and
     * well clear of the 128 MB limit that took /kaia/message down on
     * 2026-08-09. Anything bigger is served as the original instead.
     */
    private const MAX_SOURCE_PIXELS = 25_000_000;

    public const PREFIX = 'thumbs';

    /**
     * Bucket key of the variant for $sourceKey at $width, whether or not it
     * exists yet. `.webp` is appended rather than substituted so two sources
     * differing only in extension can't collide.
     */
    public static function variantKey(string $sourceKey, int $width): string
    {
        return self::PREFIX."/{$width}/".$sourceKey.'.webp';
    }

    /**
     * Return the variant's key, generating it if absent. Null means "couldn't",
     * and the caller should fall back to the original.
     */
    public function ensure(string $sourceKey, int $width): ?string
    {
        $disk = Storage::disk('r2');
        $variantKey = self::variantKey($sourceKey, $width);

        try {
            if ($disk->exists($variantKey)) {
                return $variantKey;
            }

            $source = $disk->get($sourceKey);

            if ($source === null || $source === '') {
                return null;
            }

            $webp = $this->resizeToWebp($source, $width);

            if ($webp === null) {
                return null;
            }

            // Two requests for the same missing variant can race here. They
            // write identical bytes to the same key, so the loser is harmless —
            // not worth a lock, which would cost a round trip on every miss.
            $disk->put($variantKey, $webp, 'public');

            return $variantKey;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Scale $binary down to at most $width wide and encode it as WebP.
     * Never upscales: a source narrower than the requested width is returned
     * at its own size, so a small original doesn't get blown up into a larger
     * file than it started as.
     */
    private function resizeToWebp(string $binary, int $width): ?string
    {
        $info = @getimagesizefromstring($binary);

        if ($info === false) {
            return null;
        }

        [$sourceWidth, $sourceHeight] = $info;

        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return null;
        }

        if ($sourceWidth * $sourceHeight > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return null;
        }

        try {
            $targetWidth = min($width, $sourceWidth);
            $targetHeight = (int) max(1, round($sourceHeight * ($targetWidth / $sourceWidth)));

            $resized = imagescale($image, $targetWidth, $targetHeight);

            if ($resized === false) {
                return null;
            }

            try {
                ob_start();
                // Flattens transparency onto nothing in particular for PNGs,
                // which is fine: every consumer renders these with object-fit
                // over an opaque card background.
                $ok = imagewebp($resized, null, (int) config('media.thumbnails.quality', 80));
                $out = (string) ob_get_clean();

                return $ok && $out !== '' ? $out : null;
            } finally {
                imagedestroy($resized);
            }
        } finally {
            imagedestroy($image);
        }
    }
}
