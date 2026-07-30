<?php

namespace App\Console\Commands;

use App\Enums\ListingType;
use App\Models\Listing;
use App\Models\Partner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScrapeNamibiayp extends Command
{
    protected $signature = 'listings:scrape-namibiayp
                            {--pages=3       : Pages to scrape per category (10 listings/page)}
                            {--dry-run       : Show what would be imported without saving}
                            {--no-photos     : Skip photo downloads (import metadata only)}
                            {--limit=0       : Max total listings to process (0 = all)}';

    protected $description = 'Scrape namibiayp.com for Namibian travel listings with photos';

    private const BASE = 'https://www.namibiayp.com';

    private const CATEGORIES = [
        'Lodges_Resorts'                => ListingType::Accommodation,
        'Tourism_Accommodation'         => ListingType::Accommodation,
        'Guest_houses'                  => ListingType::Accommodation,
        'Specialist_accommodation'      => ListingType::Accommodation,
        'Self_catering_accommodation'   => ListingType::Accommodation,
        'Camping_and_Outdoors'          => ListingType::Accommodation,
        'Restaurants'                   => ListingType::Restaurant,
        'Tour_operators'                => ListingType::Activity,
    ];

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $photos = 0;

    private bool $dry = false;

    private bool $noPhotos = false;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $this->noPhotos = (bool) $this->option('no-photos');
        $maxPages = (int) $this->option('pages');
        $limit = (int) $this->option('limit');

        if ($this->dry) {
            $this->warn('DRY RUN — nothing will be saved');
        }

        $this->info('Collecting company URLs from namibiayp.com…');

        $companyUrls = $this->collectUrls($maxPages);
        $this->info('Found ' . count($companyUrls) . ' unique companies');

        if ($limit > 0) {
            $companyUrls = array_slice($companyUrls, 0, $limit);
        }

        $bar = $this->output->createProgressBar(count($companyUrls));
        $bar->start();

        foreach ($companyUrls as [$url, $type]) {
            $this->processCompany($url, $type);
            $bar->advance();
            usleep(300_000); // 300ms rate limit
        }

        $bar->finish();
        $this->newLine();
        $this->table(
            ['Created', 'Updated', 'Skipped', 'Photos'],
            [[$this->created, $this->updated, $this->skipped, $this->photos]]
        );

        return self::SUCCESS;
    }

    /** @return array<int, array{string, ListingType}> */
    private function collectUrls(int $maxPages): array
    {
        $seen = [];
        $results = [];

        foreach (self::CATEGORIES as $cat => $type) {
            for ($page = 1; $page <= $maxPages; $page++) {
                $pageUrl = self::BASE . "/category/{$cat}" . ($page > 1 ? "/{$page}" : '');

                try {
                    $html = $this->fetch($pageUrl);
                    if (! $html) {
                        break;
                    }

                    preg_match_all('#/company/(\d+)/([A-Za-z0-9_-]+)#', $html, $m);

                    if (empty($m[0])) {
                        break; // No more listings on this page
                    }

                    foreach (array_unique($m[0]) as $path) {
                        if (! isset($seen[$path])) {
                            $seen[$path] = true;
                            $results[] = [self::BASE . $path, $type];
                        }
                    }

                    usleep(200_000);
                } catch (\Throwable) {
                    break;
                }
            }
        }

        return $results;
    }

    private function processCompany(string $url, ListingType $type): void
    {
        try {
            $html = $this->fetch($url);
            if (! $html) {
                $this->skipped++;

                return;
            }

            $name = $this->extractText($html, '#<h1[^>]*>(.*?)</h1>#si');
            $name = html_entity_decode(strip_tags($name ?? ''), ENT_QUOTES, 'UTF-8');
            $name = trim($name);

            if (! $name || strlen($name) < 2) {
                $this->skipped++;

                return;
            }

            $slug = Str::slug($name);
            if (! $slug) {
                $this->skipped++;

                return;
            }

            $description = $this->extractDescription($html);
            $photos = $this->extractPhotos($html);
            $website = $this->extractHref($html, '#href=["\x27](https?://(?!www\.namibiayp)[^"\'<>]{5,200})["\x27]#i');
            $phone = $this->extractText($html, '#class="[^"]*phone[^"]*"[^>]*>\s*([\d\s+()-]{6,25})<#i');
            $address = $this->extractText($html, '#class="[^"]*address[^"]*"[^>]*>(.*?)</[a-z]+>#si');
            $address = $address ? trim(strip_tags($address)) : null;
            $email = $this->extractEmail($html);

            // Extract YP company ID for idempotency
            preg_match('#/company/(\d+)/#', $url, $idm);
            $ypId = $idm[1] ?? null;

            $existing = $ypId
                ? Listing::where('scrape_source', 'namibiayp')->where('scrape_id', $ypId)->first()
                : Listing::where('slug', $slug)->first();

            if ($this->dry) {
                $this->line("\n  [dry] {$name} | photos: " . count($photos));
                $this->created++;

                return;
            }

            $heroUrl = null;
            $galleryUrls = [];

            if (! $this->noPhotos && ! empty($photos)) {
                foreach (array_slice($photos, 0, 5) as $photoUrl) {
                    $stored = $this->downloadPhoto($photoUrl, $slug);
                    if ($stored) {
                        if (! $heroUrl) {
                            $heroUrl = $stored;
                        } else {
                            $galleryUrls[] = $stored;
                        }
                        $this->photos++;
                    }
                }
            }

            if ($existing) {
                $fill = [];
                if ($heroUrl && ! $existing->image) {
                    $fill['image'] = $heroUrl;
                }
                if (! empty($galleryUrls) && empty($existing->gallery)) {
                    $fill['gallery'] = $galleryUrls;
                }
                if ($website && ! $existing->website) {
                    $fill['website'] = $website;
                }
                if ($phone && ! $existing->phone) {
                    $fill['phone'] = $phone;
                }
                if ($address && ! $existing->address) {
                    $fill['address'] = $address;
                }
                if ($fill) {
                    $existing->update($fill);
                    $this->updated++;
                } else {
                    $this->skipped++;
                }

                if (! $existing->partner_id) {
                    $existing->update(['partner_id' => $this->findOrCreatePartner($name, $url, $website, $phone, $email)->id]);
                }

                return;
            }

            $partner = $this->findOrCreatePartner($name, $url, $website, $phone, $email);

            $listing = new Listing([
                'partner_id' => $partner->id,
                'type' => $type,
                'slug' => $slug,
                'description' => $description ? ['en' => $description] : null,
                'image' => $heroUrl,
                'gallery' => $galleryUrls,
                'website' => $website,
                'phone' => $phone,
                'address' => $address,
                'source_url' => $url,
                'scrape_source' => 'namibiayp',
                'scrape_id' => $ypId,
                'scraped_at' => now(),
                'claim_status' => 'unclaimed',
                'is_published' => false,
                'accepts_inquiries' => true,
            ]);
            $listing->setTranslation('name', 'en', $name);
            $listing->save();
            $this->created++;
        } catch (\Throwable) {
            $this->skipped++;
        }
    }

    private function extractPhotos(string $html): array
    {
        // Main photos: /img/na/m/filename.jpg (no underscore = full size)
        preg_match_all('#/img/na/[a-z]/(?!_)([^"\')\s<>]+\.(?:jpg|jpeg|png|webp))#i', $html, $m);
        $paths = array_unique($m[0] ?? []);

        // Prefer /m/ (main) size, then any other
        $main = array_values(array_filter($paths, fn ($p) => str_contains($p, '/img/na/m/')));
        $others = array_values(array_filter($paths, fn ($p) => ! str_contains($p, '/img/na/m/')));

        return array_map(
            fn ($p) => self::BASE . $p,
            array_slice(array_merge($main, $others), 0, 5)
        );
    }

    private function extractDescription(string $html): ?string
    {
        // Try og:description first (clean, no HTML)
        if (preg_match('#<meta[^>]+name=["\x27]description["\x27][^>]+content=["\x27]([^"\']{20,})["\x27]#i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('#<meta[^>]+content=["\x27]([^"\']{20,})["\x27][^>]+name=["\x27]description["\x27]#i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        return null;
    }

    private function extractText(string $html, string $pattern): ?string
    {
        if (preg_match($pattern, $html, $m)) {
            $text = strip_tags($m[1] ?? '');

            return mb_strlen($text) >= 2 ? trim($text) : null;
        }

        return null;
    }

    private function extractEmail(string $html): ?string
    {
        if (preg_match('#mailto:([^"\'?\s<>]+@[^"\'?\s<>]+)#i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        return null;
    }

    private function findOrCreatePartner(string $name, string $sourceUrl, ?string $website, ?string $phone, ?string $email = null): Partner
    {
        $partner = Partner::where('source_url', $sourceUrl)->first();

        if ($partner) {
            $partner->fill(array_filter([
                'email' => $partner->email ? null : $email,
                'phone' => $partner->phone ? null : $phone,
                'website' => $partner->website ? null : $website,
            ]))->save();

            return $partner;
        }

        return Partner::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'website' => $website,
            'source_url' => $sourceUrl,
            'claim_token' => Str::random(48),
        ]);
    }

    private function extractHref(string $html, string $pattern): ?string
    {
        if (preg_match($pattern, $html, $m)) {
            $url = trim($m[1]);

            return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
        }

        return null;
    }

    private function downloadPhoto(string $url, string $slug): ?string
    {
        try {
            $response = Http::timeout(20)->withHeaders(['Referer' => self::BASE])->get($url);
            if (! $response->successful()) {
                return null;
            }

            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg');
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $ext = 'jpg';
            }

            $filename = 'listings/namibiayp/' . $slug . '-' . Str::random(8) . '.' . $ext;
            Storage::disk('public')->put($filename, $response->body());

            return '/storage/' . $filename;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetch(string $url): ?string
    {
        $response = Http::timeout(20)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; NamibWay/1.0; travel listings aggregator)',
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
