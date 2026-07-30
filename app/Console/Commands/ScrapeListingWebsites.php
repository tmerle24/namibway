<?php

namespace App\Console\Commands;

use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScrapeListingWebsites extends Command
{
    protected $signature = 'namibway:scrape-websites
                            {--limit=50    : Max listings to enrich in this run}
                            {--dry-run     : Show what would be updated without saving}';

    protected $description = 'Enrich listings from their own website using og:/meta tags (legal-friendly, owner-published data)';

    private int $enriched = 0;

    private int $skippedRobots = 0;

    private int $skippedNoData = 0;

    private bool $dry = false;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if ($this->dry) {
            $this->warn('DRY RUN — nothing will be saved');
        }

        $listings = Listing::whereNotNull('website')
            ->whereNull('og_scraped_at')
            ->limit($limit)
            ->get();

        if ($listings->isEmpty()) {
            $this->info('No listings pending website enrichment.');

            return self::SUCCESS;
        }

        $this->info("Enriching {$listings->count()} listing(s) from their own websites…");

        $bar = $this->output->createProgressBar($listings->count());
        $bar->start();

        foreach ($listings as $listing) {
            $this->enrich($listing);
            $bar->advance();
            usleep(400_000); // 400ms rate limit between different domains
        }

        $bar->finish();
        $this->newLine();
        $this->table(
            ['Enriched', 'Skipped (robots.txt)', 'Skipped (no data)'],
            [[$this->enriched, $this->skippedRobots, $this->skippedNoData]]
        );

        return self::SUCCESS;
    }

    private function enrich(Listing $listing): void
    {
        $url = $listing->website;
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            $this->markScraped($listing);
            $this->skippedNoData++;

            return;
        }

        if (! $this->robotsAllow($url)) {
            $this->skippedRobots++;

            return; // don't mark as scraped — retry later once allowed
        }

        try {
            $html = $this->fetch($url);
        } catch (\Throwable) {
            $html = null;
        }

        if (! $html) {
            $this->markScraped($listing);
            $this->skippedNoData++;

            return;
        }

        $og = $this->extractOgTags($html, $url);

        if (empty($og)) {
            $this->markScraped($listing);
            $this->skippedNoData++;

            return;
        }

        if ($this->dry) {
            $this->line("\n  [dry] {$listing->getTranslation('name', 'en')} | ".json_encode($og));
            $this->enriched++;

            return;
        }

        $fill = [];

        if (! empty($og['image']) && ! $listing->image) {
            $stored = $this->downloadPhoto($og['image'], $listing->slug);
            if ($stored) {
                $fill['image'] = $stored;
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

        $fill['scrape_data'] = array_merge($listing->scrape_data ?? [], ['og' => $og]);

        $listing->update($fill);
        $this->markScraped($listing);

        if ($listing->partner) {
            $listing->partner->fill(array_filter([
                'email' => $listing->partner->email ? null : ($og['email'] ?? null),
                'phone' => $listing->partner->phone ? null : ($og['phone'] ?? null),
            ]))->save();
        }

        $this->enriched++;
    }

    private function markScraped(Listing $listing): void
    {
        if ($this->dry) {
            return;
        }

        $listing->update(['og_scraped_at' => now()]);
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

            $filename = 'listings/website-enrichment/'.$slug.'-'.Str::random(8).'.'.$ext;
            Storage::disk('public')->put($filename, $response->body());

            return '/storage/'.$filename;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetch(string $url): ?string
    {
        $response = Http::timeout(15)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; NamibWay/1.0; +https://namibway.com; travel listings enrichment)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        return $response->body();
    }
}
