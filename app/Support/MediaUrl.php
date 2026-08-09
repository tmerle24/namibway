<?php

namespace App\Support;

/**
 * Turns a stored media URL into a right-sized one.
 *
 * Everything the app stores is a full-size original: Google Places photos come
 * down at 1200px (GooglePlacesPhotoFinder), Filament uploads are capped at
 * 2000px, and scraped/Unsplash heroes are external URLs we don't own at all.
 * Handing those straight to a 44px trip-plan day thumbnail is what made image
 * lists load slowly, so every <img> that renders smaller than the original
 * should ask for a width here instead of using the raw URL.
 *
 * Nothing is generated or stored — Cloudflare resizes the same original on the
 * fly. See config/media.php for how that's wired and when it's inert.
 *
 * The frontend mirror of this lives in resources/js/lib/media.ts; the two are
 * kept deliberately parallel, because components know their own render size
 * and the backend doesn't (the same listing payload feeds a 44px thumbnail and
 * a full-bleed hero).
 */
class MediaUrl
{
    /**
     * Resize $url for a slot $width CSS pixels wide, or return it untouched
     * when it isn't a URL we can resize. Null in, null out, so callers can
     * `v-if` / `@if` on the result exactly like they did on the raw column.
     */
    public static function thumb(?string $url, int $width): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $snapped = self::snapWidth($width);

        // Already transformed (e.g. a URL that made a round trip through a
        // saved plan) — rewrapping would nest /cdn-cgi/image/ inside itself.
        if (str_contains($url, '/cdn-cgi/image/')) {
            return $url;
        }

        if (self::isUnsplash($url)) {
            return self::resizeUnsplash($url, $snapped);
        }

        if (! config('media.transforms.enabled')) {
            return $url;
        }

        $rewritten = self::rewriteThroughCloudflare($url, $snapped);

        return $rewritten ?? $url;
    }

    /**
     * A `srcset` value covering standard and retina screens for a fixed-size
     * slot — `"…/width=64/x.jpg 1x, …/width=128/x.jpg 2x"`. Returns null when
     * both densities resolve to the same URL (an un-resizable external image,
     * or two densities that snapped onto the same ladder rung), so the caller
     * can leave the attribute off rather than emit a pointless one.
     */
    public static function srcset(?string $url, int $width): ?string
    {
        $single = self::thumb($url, $width);
        $double = self::thumb($url, $width * 2);

        if ($single === null || $double === null || $single === $double) {
            return null;
        }

        return $single.' 1x, '.$double.' 2x';
    }

    /**
     * Snap a requested width up to the configured ladder, so near-identical
     * slots share one cached variant instead of billing one each. Widths past
     * the top rung clamp to it — we never ask for more than the original.
     */
    public static function snapWidth(int $width): int
    {
        /** @var list<int> $ladder */
        $ladder = config('media.transforms.widths', []);

        if ($ladder === []) {
            return $width;
        }

        foreach ($ladder as $rung) {
            if ($width <= $rung) {
                return $rung;
            }
        }

        return (int) end($ladder);
    }

    /**
     * The subset of config the frontend mirror needs, shared as an Inertia prop.
     *
     * @return array{enabled: bool, origins: list<string>, widths: list<int>, quality: int}
     */
    public static function clientConfig(): array
    {
        return [
            'enabled' => (bool) config('media.transforms.enabled'),
            'origins' => array_values(array_map(
                static fn (string $origin): string => rtrim($origin, '/'),
                (array) config('media.transforms.origins', []),
            )),
            'widths' => array_values((array) config('media.transforms.widths', [])),
            'quality' => (int) config('media.transforms.quality', 80),
        ];
    }

    /**
     * Splice `/cdn-cgi/image/<options>` in after the origin. Returns null when
     * the URL isn't one we serve — a scraped operator's own website, say —
     * since Cloudflare would only 404 on it.
     */
    private static function rewriteThroughCloudflare(string $url, int $width): ?string
    {
        $options = self::options($width);

        // Root-relative URLs (bundled fallbacks, legacy /storage uploads) are
        // the app origin, which is NOT Cloudflare-proxied — see config/media.php
        // for why only the media CDN origin qualifies.
        foreach (self::clientConfig()['origins'] as $origin) {
            if ($origin !== '' && str_starts_with($url, $origin.'/')) {
                return $origin.'/cdn-cgi/image/'.$options.substr($url, strlen($origin));
            }
        }

        return null;
    }

    private static function options(int $width): string
    {
        // Ordered deliberately: the option string is part of the cache key, so
        // it has to be byte-identical for every caller asking the same thing.
        // `scale-down` never upscales, which matters because the ladder's top
        // rung is wider than some of the originals it gets applied to.
        return 'format=auto,fit=scale-down,width='.$width
            .',quality='.(int) config('media.transforms.quality', 80);
    }

    private static function isUnsplash(string $url): bool
    {
        return str_starts_with($url, 'https://images.unsplash.com/');
    }

    /**
     * Unsplash resizes off its own query parameters, so the placeholder heroes
     * AssignListingPhotos writes (hardcoded `?w=1200`) can be shrunk with no
     * Cloudflare involvement and no cost — this works even while transforms
     * are switched off.
     */
    private static function resizeUnsplash(string $url, int $width): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['path'])) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        $query['w'] = (string) $width;

        return 'https://images.unsplash.com'.$parts['path'].'?'.http_build_query($query);
    }
}
