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

            $type = $this->resolveType($r['type'] ?? 'accommodation');
            $slug = $this->uniqueSlug(Str::slug($name));

            $fields = [
                'type' => $type,
                'region' => $r['region'] ?? null,
                'description' => $this->localise($r['description'] ?? null),
                'latitude' => $r['latitude'] ?? null,
                'longitude' => $r['longitude'] ?? null,
                'website' => $this->cleanUrl($r['website'] ?? null),
                'contact_email' => $r['contact_email'] ?? null,
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
                $type = $this->resolveType($r['type'] ?? 'accommodation');
                $slug = $this->uniqueSlug(Str::slug($name));

                $fields = [
                    'type' => $type,
                    'region' => $r['region'] ?? null,
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

    private function resolveType(string $raw): string
    {
        $map = [
            'accommodation' => ListingType::Accommodation->value,
            'activity' => ListingType::Activity->value,
            'restaurant' => ListingType::Restaurant->value,
            'vehicle' => ListingType::Vehicle->value,
        ];

        return $map[strtolower($raw)] ?? ListingType::Accommodation->value;
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
