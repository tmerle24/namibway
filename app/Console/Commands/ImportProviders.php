<?php

namespace App\Console\Commands;

use App\Enums\ListingType;
use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ImportProviders extends Command
{
    protected $signature = 'providers:import
        {--ntb=        : Path to scraped_providers.json (NTB/visitnamibia)}
        {--yp=         : Path to namibiayp_contacts.json}
        {--dry-run     : Preview changes without writing to DB}
        {--no-merge    : Skip name-similarity merge between sources}';

    protected $description = 'Import scraped provider data into listings table (one-time, idempotent via slug upsert)';

    private bool $dry = false;

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private const VEHICLE_KEYWORDS = [
        'car rental', 'car rentals', 'car hire', 'car-hire',
        'vehicle hire', 'vehicle rental', 'vehicle lease',
        '4x4 hire', '4x4 rental', 'campervan hire', 'camper hire',
        'camper rental', 'equipment rental', 'equipment rentals', 'equipment hire',
        'rent a car',
    ];

    private const ACTIVITY_KEYWORDS = [
        'tours and safaris', 'tours & safaris', 'transfers & tours', 'transfers and tours',
        'safari', 'safaris',
    ];

    /** Names containing these are genuine accommodation businesses even if they also match an activity/vehicle keyword (e.g. "Etosha Safari Lodge"). */
    private const ACCOMMODATION_OVERRIDE_KEYWORDS = [
        'lodge', 'camp', 'guest house', 'guesthouse', 'hotel', 'resort', 'farm',
        'backpackers', 'cottage', 'chalet', 'b&b', 'bed and breakfast',
    ];

    /** Not a travel business at all — no correct ListingType exists, so leave unpublished rather than force a wrong category. */
    private const NON_TRAVEL_KEYWORDS = [
        'engineering', 'marine services', 'shipwrights', 'mart', 'maintenance',
        'service centre', 'service center',
    ];

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');

        if ($this->dry) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $ntbPath = $this->option('ntb') ?: base_path('data/scraped/scraped_providers.json');
        $ypPath = $this->option('yp') ?: base_path('data/scraped/namibiayp_contacts.json');

        if (file_exists($ntbPath)) {
            $this->info("Importing NTB records from {$ntbPath}…");
            $this->importNtb($ntbPath);
        } else {
            $this->warn("NTB file not found: {$ntbPath} — skipping");
        }

        if (file_exists($ypPath)) {
            $this->info("Importing NamibiaYP records from {$ypPath}…");
            $this->importYp($ypPath, ! $this->option('no-merge'));
        } else {
            $this->warn("NamibiaYP file not found: {$ypPath} — skipping");
        }

        $this->newLine();
        $this->table(
            ['Created', 'Updated', 'Skipped'],
            [[$this->created, $this->updated, $this->skipped]]
        );

        return self::SUCCESS;
    }

    private function importNtb(string $path): void
    {
        $records = json_decode((string) file_get_contents($path), true);
        $bar = $this->output->createProgressBar(count($records));
        $bar->start();

        $now = Carbon::now();

        foreach ($records as $r) {
            $name = trim($r['name'] ?? '');
            if (! $name) {
                $this->skipped++;
                $bar->advance();

                continue;
            }

            $type = $this->resolveType($r['type'] ?? '', $name);
            $slug = $this->uniqueSlug(Str::slug($name));

            $fields = [
                'type' => $type,
                // $r['region'] (free text) has no reliable match against a City —
                // city_id is left for an admin to assign manually after import.
                'description' => $this->localise($r['description'] ?? null),
                'latitude' => $r['latitude'] ?? null,
                'longitude' => $r['longitude'] ?? null,
                'website' => $this->cleanUrl($r['website'] ?? null),
                'contact_email' => $r['email'] ?? null,
                'phone' => $r['phone'] ?? null,
                'source_url' => $r['source_url'] ?? null,
                'scrape_source' => 'ntb',
                'scraped_at' => $now,
                'claim_status' => 'unclaimed',
                'is_published' => false,
            ];

            $this->upsertListing($slug, $name, $fields);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function importYp(string $path, bool $merge): void
    {
        $records = json_decode((string) file_get_contents($path), true);
        $bar = $this->output->createProgressBar(count($records));
        $bar->start();

        $now = Carbon::now();

        $existing = $merge
            ? Listing::select('id', 'name', 'phone', 'address', 'website', 'latitude', 'longitude')
                ->get()
                ->keyBy('id')
                ->all()
            : [];

        foreach ($records as $r) {
            $name = trim($r['name'] ?? '');
            if (! $name) {
                $this->skipped++;
                $bar->advance();

                continue;
            }

            $match = $merge ? $this->findSimilar($name, $existing, 0.82) : null;

            if ($match) {
                $fill = [];
                if (! $match->phone && ($r['phone'] ?? null)) {
                    $fill['phone'] = $r['phone'];
                }
                if (! $match->address && ($r['address'] ?? null)) {
                    $fill['address'] = $r['address'];
                }
                if (! $match->website && ($r['website'] ?? null)) {
                    $fill['website'] = $this->cleanUrl($r['website']);
                }
                if (! $match->latitude && ($r['latitude'] ?? null)) {
                    $fill['latitude'] = $r['latitude'];
                }
                if (! $match->longitude && ($r['longitude'] ?? null)) {
                    $fill['longitude'] = $r['longitude'];
                }

                if ($fill) {
                    if (! $this->dry) {
                        Listing::where('id', $match->id)->update($fill);
                    }
                    $this->updated++;
                } else {
                    $this->skipped++;
                }
            } else {
                $type = $this->resolveType($r['type'] ?? '', $name);
                $slug = $this->uniqueSlug(Str::slug($name));

                $fields = [
                    'type' => $type,
                    // $r['region'] (free text) has no reliable match against a City —
                    // city_id is left for an admin to assign manually after import.
                    'latitude' => $r['latitude'] ?? null,
                    'longitude' => $r['longitude'] ?? null,
                    'website' => $this->cleanUrl($r['website'] ?? null),
                    'phone' => $r['phone'] ?? null,
                    'address' => $r['address'] ?? null,
                    'scrape_source' => 'namibiayp',
                    'scraped_at' => $now,
                    'claim_status' => 'unclaimed',
                    'is_published' => false,
                ];

                $this->upsertListing($slug, $name, $fields);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /** @param array<string, mixed> $fields */
    private function upsertListing(string $slug, string $name, array $fields): void
    {
        if ($this->dry) {
            $this->created++;

            return;
        }

        $existing = Listing::where('slug', $slug)->first();

        if ($existing) {
            $fill = [];
            foreach ($fields as $k => $v) {
                if ($v !== null && blank($existing->getAttributeValue($k))) {
                    $fill[$k] = $v;
                }
            }
            if ($fill) {
                $existing->update($fill);
                $this->updated++;
            } else {
                $this->skipped++;
            }
        } else {
            $listing = new Listing($fields);
            $listing->setTranslation('name', 'en', $name);
            $listing->slug = $slug;
            $listing->save();
            $this->created++;
        }
    }

    /** @param array<int|string, Listing> $pool */
    private function findSimilar(string $name, array $pool, float $threshold): ?Listing
    {
        $best = null;
        $bestScore = 0.0;
        $nameLower = mb_strtolower(trim($name));

        foreach ($pool as $listing) {
            $candidate = mb_strtolower(trim((string) $listing->name));
            similar_text($nameLower, $candidate, $pct);
            $score = $pct / 100;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $listing;
            }
        }

        return ($bestScore >= $threshold) ? $best : null;
    }

    private function resolveType(string $raw, string $name): string
    {
        $map = [
            'accommodation' => ListingType::Accommodation->value,
            'activity' => ListingType::Activity->value,
            'restaurant' => ListingType::Restaurant->value,
            'vehicle' => ListingType::Vehicle->value,
            // What the NTB directory actually calls its 253 rental entries —
            // it never says "vehicle". Without this they fell through to the
            // keyword guess below, which reads the business NAME and so
            // misfiled every rental whose name also mentions where it sleeps
            // its guests ("Discovery Guest House And Car Hire" → accommodation,
            // "Self Drive Safaris" → activity). The source stating its own
            // type outranks anything guessed from a name.
            'car_rental' => ListingType::Vehicle->value,
        ];

        $key = strtolower(trim($raw));
        if (isset($map[$key])) {
            return $map[$key];
        }

        $hasAccommodationOverride = $this->matchesAny($name, self::ACCOMMODATION_OVERRIDE_KEYWORDS);

        if (! $hasAccommodationOverride && $this->matchesAny($name, self::VEHICLE_KEYWORDS)) {
            return ListingType::Vehicle->value;
        }

        if (! $hasAccommodationOverride && $this->matchesAny($name, self::ACTIVITY_KEYWORDS)) {
            return ListingType::Activity->value;
        }

        if (! $hasAccommodationOverride && $this->matchesAny($name, self::NON_TRAVEL_KEYWORDS)) {
            $this->warn("  No ListingType fits '{$name}' (looks non-travel) — imported as accommodation but left unpublished; review manually.");
        }

        return ListingType::Accommodation->value;
    }

    /** @param array<int, string> $keywords */
    private function matchesAny(string $name, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string>|null */
    private function localise(?string $text): ?array
    {
        return $text ? ['en' => $text] : null;
    }

    private function cleanUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $url = trim($url);

        return (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))
            ? $url
            : 'https://'.$url;
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base ?: 'listing';
        $i = 0;
        while (Listing::where('slug', $slug)->exists()) {
            $i++;
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
