<?php

namespace App\Jobs;

use App\Models\Listing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Crawls a single listing's own website for og:/meta tags and photos
 * (legal-friendly, owner-published data) and stores what it finds on R2.
 * Dispatched repeatedly over time by namibway:scrape-websites so listings
 * get re-checked periodically rather than only ever once.
 */
class CrawlListingWebsiteJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 45;

    /** Dedupe window: don't queue the same listing twice while one crawl is in flight/pending. */
    public int $uniqueFor = 3600;

    /** Minimum seconds between two requests to the same host, enforced across all workers. */
    private const HOST_COOLDOWN_SECONDS = 3;

    /** Max images (hero + gallery) to download per listing. */
    private const MAX_IMAGES = 6;

    public function __construct(public readonly int $listingId) {}

    public function uniqueId(): string
    {
        return (string) $this->listingId;
    }

    public function handle(): void
    {
        $listing = Listing::find($this->listingId);

        if (! $listing || ! $listing->website) {
            return;
        }

        $host = parse_url($listing->website, PHP_URL_HOST);

        if (! $host) {
            $this->markScraped($listing);

            return;
        }

        if (! $this->claimHostSlot($host)) {
            $this->release(self::HOST_COOLDOWN_SECONDS);

            return;
        }

        if (! $this->robotsAllow($listing->website)) {
            return; // don't mark as scraped — retry on the next refresh cycle
        }

        $html = $this->fetch($listing->website);

        if (! $html) {
            $this->markScraped($listing);

            return;
        }

        $og = $this->extractOgTags($html, $listing->website);
        $images = $this->extractGalleryImages($html, $listing->website, $og['image'] ?? null);

        if (empty($og) && empty($images)) {
            $this->markScraped($listing);

            return;
        }

        $fill = $this->buildFillableUpdates($listing, $og, $images);

        $fill['scrape_data'] = array_merge($listing->scrape_data ?? [], [
            'og' => $og,
            'crawled_at' => now()->toIso8601String(),
        ]);

        $listing->update($fill);
        $this->markScraped($listing);

        if ($listing->partner) {
            $listing->partner->fill(array_filter([
                'email' => $listing->partner->email ? null : ($og['email'] ?? null),
                'phone' => $listing->partner->phone ? null : ($og['phone'] ?? null),
            ]))->save();
        }
    }

    /** @param array<string, string> $og
     *  @param list<string> $images
     *  @return array<string, mixed> */
    private function buildFillableUpdates(Listing $listing, array $og, array $images): array
    {
        $fill = [];

        if (! empty($images) && ! $listing->image) {
            $hero = $this->downloadPhoto(array_shift($images), $listing->slug);
            if ($hero) {
                $fill['image'] = $hero;
            }
        }

        if (! empty($images) && empty($listing->gallery)) {
            $gallery = [];
            foreach (array_slice($images, 0, self::MAX_IMAGES - 1) as $url) {
                $stored = $this->downloadPhoto($url, $listing->slug);
                if ($stored) {
                    $gallery[] = $stored;
                }
            }
            if ($gallery) {
                $fill['gallery'] = $gallery;
            }
        }

        if (! empty($og['description']) && blank($listing->getTranslation('description', 'en'))) {
            $fill['description'] = ['en' => $og['description']];
        }

        if (! empty($og['email']) && ! $listing->contact_email) {
            $fill['contact_email'] = $og['email'];
        }

        if (! empty($og['phone']) && ! $listing->phone) {
            $fill['phone'] = $og['phone'];
        }

        return $fill;
    }

    private function markScraped(Listing $listing): void
    {
        $listing->update(['og_scraped_at' => now()]);
    }

    /** Atomically claim a per-host crawl slot; returns false if another worker crawled this host too recently. */
    private function claimHostSlot(string $host): bool
    {
        return Cache::add("crawl-host-cooldown:{$host}", true, self::HOST_COOLDOWN_SECONDS);
    }

    /** @return array<string, string> */
    private function extractOgTags(string $html, string $baseUrl): array
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
    private function extractGalleryImages(string $html, string $baseUrl, ?string $heroUrl): array
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

                if (count($found) >= self::MAX_IMAGES * 3) {
                    break; // enough raw candidates — dedupe below trims to MAX_IMAGES
                }
            }
        }

        return array_slice(array_values(array_unique($found)), 0, self::MAX_IMAGES);
    }

    private function resolveUrl(string $maybeRelative, string $baseUrl): string
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

    private function robotsAllow(string $url): bool
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

    private function downloadPhoto(string $url, string $slug): ?string
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

            $filename = 'listings/website-crawl/'.$slug.'-'.Str::random(10).'.'.$ext;
            Storage::disk('r2')->put($filename, $response->body());

            return Storage::disk('r2')->url($filename);
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetch(string $url): ?string
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
}
