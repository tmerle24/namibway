<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared homepage-fetching/extraction primitives: polite crawling (robots.txt,
 * per-host rate limiting, a self-identifying User-Agent), og:/meta tag and
 * contact-info extraction, and gallery-image discovery + R2 download.
 *
 * Originally lived inline in CrawlListingWebsiteJob; pulled out so the
 * broader enrichment pipeline (WebsiteFinderService, AI extraction) can reuse
 * the exact same fetch/parse behavior instead of re-implementing it.
 */
class WebsiteContentExtractor
{
    /** Minimum seconds between two requests to the same host, enforced across all workers. */
    public const HOST_COOLDOWN_SECONDS = 3;

    /** Max images (hero + gallery) to discover per page by default. */
    public const MAX_IMAGES = 6;

    /** Atomically claim a per-host crawl slot; returns false if another worker crawled this host too recently. */
    public function claimHostSlot(string $host): bool
    {
        return Cache::add("crawl-host-cooldown:{$host}", true, self::HOST_COOLDOWN_SECONDS);
    }

    public function robotsAllow(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';

        try {
            $response = Http::timeout(8)->get("{$scheme}://{$host}/robots.txt");
        } catch (\Throwable) {
            return true; // no robots.txt reachable — proceed
        }

        if (! $response->successful()) {
            return true;
        }

        $body = $response->body();

        // Naive check: a wildcard user-agent block that disallows the whole site.
        if (preg_match('#User-agent:\s*\*\s*\n(?:Disallow:\s*/\s*\n?)+#i', $body)) {
            return false;
        }

        return true;
    }

    public function fetchHomepage(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; NamibWay/1.0; +https://namibway.com; travel listings enrichment)',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $response->body();
    }

    /**
     * Extracts og:/meta title, description, image, site_name plus a
     * best-effort mailto:/tel: contact email and phone.
     *
     * @return array<string, string>
     */
    public function extractSignals(string $html, string $baseUrl): array
    {
        $og = [];

        foreach (['og:title' => 'title', 'og:description' => 'description', 'og:image' => 'image', 'og:site_name' => 'site_name'] as $property => $key) {
            $value = $this->extractMeta($html, $property);
            if ($value) {
                $og[$key] = $value;
            }
        }

        if (empty($og['title']) && preg_match('#<title[^>]*>(.*?)</title>#si', $html, $m)) {
            $og['title'] = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
        }

        if (empty($og['description']) && preg_match('#<meta[^>]+name=["\x27]description["\x27][^>]+content=["\x27]([^"\']{20,})["\x27]#i', $html, $m)) {
            $og['description'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        if (! empty($og['image'])) {
            $og['image'] = $this->resolveUrl($og['image'], $baseUrl);
        }

        if (preg_match('#mailto:([^"\'?\s<>]+@[^"\'?\s<>]+)#i', $html, $m)) {
            $og['email'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        if (preg_match('#(?:tel:)\s*([\d\s+()-]{6,25})#i', $html, $m)) {
            $og['phone'] = trim($m[1]);
        }

        return $og;
    }

    private function extractMeta(string $html, string $property): ?string
    {
        $pattern = '#<meta[^>]+(?:property|name)=["\x27]'.preg_quote($property, '#').'["\x27][^>]+content=["\x27]([^"\']*)["\x27]#i';
        if (preg_match($pattern, $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8') ?: null;
        }

        // content attribute can come before the property attribute
        $pattern = '#<meta[^>]+content=["\x27]([^"\']*)["\x27][^>]+(?:property|name)=["\x27]'.preg_quote($property, '#').'["\x27]#i';
        if (preg_match($pattern, $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8') ?: null;
        }

        return null;
    }

    /**
     * Scans <img> tags for real photos beyond the single og:image — logos,
     * icons and tracking pixels are filtered out by filename heuristics.
     *
     * @return list<string>
     */
    public function extractGalleryImages(string $html, string $baseUrl, ?string $heroUrl, int $max = self::MAX_IMAGES): array
    {
        $found = [];

        if ($heroUrl) {
            $found[] = $heroUrl;
        }

        if (preg_match_all('#<img[^>]+(?:data-src|data-lazy-src|src)=["\']([^"\']+)["\']#i', $html, $matches)) {
            foreach ($matches[1] as $src) {
                if (str_starts_with($src, 'data:')) {
                    continue;
                }

                if (preg_match('#(logo|icon|sprite|favicon|placeholder|pixel\.|blank\.|spinner|loader|avatar)#i', $src)) {
                    continue;
                }

                if (! preg_match('#\.(jpe?g|png|webp)(\?[^"\']*)?$#i', $src)) {
                    continue;
                }

                $found[] = $this->resolveUrl($src, $baseUrl);

                if (count($found) >= $max * 3) {
                    break; // enough raw candidates — dedupe below trims to $max
                }
            }
        }

        return array_slice(array_values(array_unique($found)), 0, $max);
    }

    public function resolveUrl(string $maybeRelative, string $baseUrl): string
    {
        if (filter_var($maybeRelative, FILTER_VALIDATE_URL)) {
            return $maybeRelative;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        if (str_starts_with($maybeRelative, '//')) {
            return "{$scheme}:{$maybeRelative}";
        }

        if (str_starts_with($maybeRelative, '/')) {
            return "{$scheme}://{$host}{$maybeRelative}";
        }

        return "{$scheme}://{$host}/".ltrim($maybeRelative, '/');
    }

    /** Downloads a remote image to R2 under listings/{$directory}/{$slug}-{random}.{ext}. */
    public function downloadPhoto(string $url, string $slug, string $directory = 'website-crawl'): ?string
    {
        try {
            $response = Http::timeout(20)->get($url);
            if (! $response->successful()) {
                return null;
            }

            $contentType = $response->header('Content-Type');
            $ext = match (true) {
                str_contains((string) $contentType, 'png') => 'png',
                str_contains((string) $contentType, 'webp') => 'webp',
                default => 'jpg',
            };

            $filename = "listings/{$directory}/{$slug}-".Str::random(10).".{$ext}";
            Storage::disk('r2')->put($filename, $response->body());

            return Storage::disk('r2')->url($filename);
        } catch (\Throwable) {
            return null;
        }
    }
}
