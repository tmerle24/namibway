<?php

namespace App\Console\Commands;

use App\Enums\ContentSource;
use App\Enums\ListingType;
use App\Models\City;
use App\Models\Listing;
use App\Models\Partner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imports scripts/scrape_namibweb.py's output into listings.
 *
 * The interesting problem here is not the first import, it's the tenth: the
 * scraper re-runs, namibweb has edited half a dozen pages, and by then we have
 * rewritten descriptions, replaced photos and corrected addresses ourselves. A
 * plain upsert would either overwrite our work or freeze the listing forever.
 *
 * So this command decides per field, from two signals:
 *
 *   1. Did *they* change it? Each record carries per-field hashes from the
 *      scraper (name/description/contact/photos/social/facts). If the hash
 *      matches what we imported last time, upstream did not touch the field
 *      and there is nothing to do — no diffing, no writes.
 *   2. Did *we* change it? Every write records the exact value it wrote in
 *      scrape_data.namibweb.imported. If the column still equals that value,
 *      it is ours to update. If it differs, someone (or the AI rewrite) edited
 *      it since, and upstream's new value is reported as a conflict instead of
 *      silently clobbering the edit.
 *
 * Everything namibweb publishes is kept verbatim under scrape_data.namibweb.raw
 * whether or not it is used, so a later decision never means scraping again.
 * Photos are staged in pending_image/pending_gallery — the same review queue
 * the website scraper uses — and never auto-published: we hold no rights to
 * namibweb's photography, and the owner (or an admin on their behalf) has to
 * approve it first.
 */
class ImportNamibweb extends Command
{
    protected $signature = 'listings:import-namibweb
        {--file=              : Path to namibweb_listings.json}
        {--photos-dir=        : Directory the scraper downloaded photos into}
        {--limit=0            : Max records to process (0 = all)}
        {--only=              : Comma-separated scrape_ids to process}
        {--changed-only       : Skip records the scraper marked unchanged}
        {--with-descriptions  : Also write upstream text into the description column}
        {--no-photos          : Skip staging photos}
        {--dry-run            : Report what would change without writing}';

    protected $description = 'Import namibweb.com scraped listings, updating only what actually changed upstream';

    public const SOURCE = 'namibweb';

    /** Nearest-city matching only trusts a hit this close to the listing's GPS. */
    private const CITY_MATCH_RADIUS_KM = 35.0;

    /** Fields this importer owns; each maps to a hash group emitted by the scraper. */
    private const FIELD_GROUPS = [
        'name' => ['name'],
        'description' => ['description'],
        'contact' => ['website', 'contact_email', 'phone', 'address'],
        'social' => ['social_links'],
        'facts' => ['latitude', 'longitude', 'price_from', 'price_currency', 'city_id'],
    ];

    private bool $dry = false;

    private int $created = 0;

    private int $updated = 0;

    private int $unchanged = 0;

    private int $skipped = 0;

    private int $photosStaged = 0;

    /** @var array<int, array{listing: string, field: string, ours: string, theirs: string}> */
    private array $conflicts = [];

    /** @var array<int, array{listing: string, url: string}> */
    private array $vanished = [];

    /** @var \Illuminate\Support\Collection<int, City>|null */
    private ?\Illuminate\Support\Collection $cities = null;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');

        $path = $this->option('file') ?: base_path('data/scraped/namibweb_listings.json');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        $records = $payload['records'] ?? $payload;

        if (! is_array($records)) {
            $this->error('Unexpected JSON shape — expected {"records": [...]} or a bare array');

            return self::FAILURE;
        }

        if ($only = $this->option('only')) {
            $wanted = array_filter(array_map('trim', explode(',', $only)));
            $records = array_filter($records, fn ($r) => in_array($r['scrape_id'] ?? null, $wanted, true));
        }

        if ($this->option('changed-only')) {
            $records = array_filter($records, fn ($r) => ($r['status'] ?? null) !== 'unchanged');
        }

        if ($limit = (int) $this->option('limit')) {
            $records = array_slice(array_values($records), 0, $limit);
        }

        if ($this->dry) {
            $this->warn('DRY RUN — nothing will be written');
        }

        $this->info('Processing '.count($records).' records from '.$path);

        $bar = $this->output->createProgressBar(count($records));
        $bar->start();

        foreach ($records as $record) {
            try {
                $this->importRecord($record);
            } catch (\Throwable $e) {
                $this->skipped++;
                $this->newLine();
                $this->warn("  {$record['scrape_id']}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Created', 'Updated', 'Unchanged', 'Skipped', 'Photos staged'],
            [[$this->created, $this->updated, $this->unchanged, $this->skipped, $this->photosStaged]]
        );

        $this->reportConflicts();
        $this->reportVanished();

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $record */
    private function importRecord(array $record): void
    {
        $scrapeId = $record['scrape_id'] ?? null;
        $name = trim((string) ($record['name'] ?? ''));

        if (! $scrapeId || $name === '') {
            $this->skipped++;

            return;
        }

        $listing = $this->findListing($scrapeId, $name);

        // A page that stopped existing upstream is reported, never unpublished:
        // namibweb going quiet about a lodge is not evidence the lodge closed.
        if (($record['gone'] ?? false) === true) {
            if ($listing) {
                $this->vanished[] = ['listing' => $listing->name, 'url' => (string) ($record['source_url'] ?? '')];
                if (! $this->dry) {
                    $this->writeScrapeData($listing, $record, $listing->scrape_data['namibweb']['imported'] ?? [], [
                        'gone_since' => $record['gone_since'] ?? now()->toIso8601String(),
                    ]);
                }
            }
            $this->skipped++;

            return;
        }

        $incoming = $this->mapFields($record);

        if (! $listing) {
            $this->createListing($record, $incoming);

            return;
        }

        $this->updateListing($listing, $record, $incoming);
    }

    /**
     * Match on the scraper's stable page id first; fall back to the name only
     * for listings no other source already owns, so an NTB or namibiayp record
     * gets enriched rather than duplicated — and never hijacked.
     */
    private function findListing(string $scrapeId, string $name): ?Listing
    {
        $byId = Listing::query()
            ->where('scrape_source', self::SOURCE)
            ->where('scrape_id', $scrapeId)
            ->first();

        if ($byId) {
            return $byId;
        }

        // Listings another source owns record our id in scrape_data instead of
        // taking over scrape_source.
        $byRecordedId = Listing::query()
            ->where('scrape_data->namibweb->scrape_id', $scrapeId)
            ->first();

        if ($byRecordedId) {
            return $byRecordedId;
        }

        // Same property, different directory: match on the slug rather than the
        // raw name, so casing and punctuation ("Joe's Camp" / "Joes Camp")
        // don't create a duplicate row.
        return Listing::query()
            ->where('name->en', $name)
            ->orWhere('slug', Str::slug($name))
            ->first();
    }

    /**
     * Scraped record → column values. Everything here is a *candidate*; which
     * of them actually get written is decided per field in updateListing().
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function mapFields(array $record): array
    {
        $geo = $record['geo'] ?? [];

        // Detail pages often carry GPS and nothing else — the scraper's
        // Nominatim pass turns that into a street and a town, which is the only
        // address such a listing will ever have.
        $address = $record['address'] ?? null;
        if (! $address && $geo) {
            $address = collect([$geo['road'] ?? null, $geo['place'] ?? null, $geo['postcode'] ?? null])
                ->filter()
                ->implode(', ') ?: ($geo['display_name'] ?? null);
        }

        return [
            'name' => trim((string) $record['name']),
            'description' => $record['description'] ?? null,
            'website' => $record['website'] ?? null,
            'contact_email' => $record['email'] ?? null,
            'phone' => $record['phone'] ?? null,
            'address' => $address ?: null,
            'social_links' => $record['social_links'] ?: null,
            'latitude' => $record['latitude'] ?? null,
            'longitude' => $record['longitude'] ?? null,
            'price_from' => $record['price_from'] ?? null,
            'price_currency' => $record['price_currency'] ?? null,
            'city_id' => $this->resolveCityId($record),
        ];
    }

    /**
     * Resolves a City from coordinates first, name second.
     *
     * Deliberately the opposite order from namibway:backfill-listing-cities'
     * original mistake: a free-text "Windhoek" in a postal address is how a
     * remote lodge ends up filed in the capital, whereas GPS cannot lie about
     * where a property is. The name path only runs when there are no usable
     * coordinates, and only on an exact match.
     */
    private function resolveCityId(array $record): ?int
    {
        $lat = $record['latitude'] ?? null;
        $lng = $record['longitude'] ?? null;

        if ($lat !== null && $lng !== null) {
            $nearest = null;
            $nearestDistance = INF;

            // Namibia has a few hundred cities/settlements, so the nearest-city
            // search runs in PHP over one cached list rather than as trigonometry
            // in SQL — no maths extensions, no dialect differences.
            foreach ($this->cities() as $city) {
                $distance = $this->distanceKm((float) $lat, (float) $lng, $city->lat, $city->lng);

                if ($distance < $nearestDistance) {
                    $nearest = $city;
                    $nearestDistance = $distance;
                }
            }

            if ($nearest && $nearestDistance <= self::CITY_MATCH_RADIUS_KM) {
                return $nearest->id;
            }
        }

        $place = $record['geo']['place'] ?? null;

        if ($place) {
            foreach ($this->cities() as $city) {
                if (Str::lower($city->name) === Str::lower($place)) {
                    return $city->id;
                }
            }
        }

        return null;
    }

    /** @return \Illuminate\Support\Collection<int, City> */
    private function cities(): \Illuminate\Support\Collection
    {
        return $this->cities ??= City::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'name', 'lat', 'lng']);
    }

    /** Equirectangular approximation — accurate well past the match radius. */
    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $x = ($lng2 - $lng1) * cos(deg2rad(($lat1 + $lat2) / 2)) * 111.32;
        $y = ($lat2 - $lat1) * 110.57;

        return sqrt($x * $x + $y * $y);
    }

    /** @param array<string, mixed> $record */
    private function createListing(array $record, array $incoming): void
    {
        if ($this->dry) {
            $this->created++;

            return;
        }

        $listing = new Listing([
            'partner_id' => $this->findOrCreatePartner($record)->id,
            'type' => $this->resolveType($record),
            'slug' => $this->uniqueSlug(Str::slug($incoming['name'])),
            'website' => $incoming['website'],
            'social_links' => $incoming['social_links'],
            'contact_email' => $incoming['contact_email'],
            'phone' => $incoming['phone'],
            'address' => $incoming['address'],
            'city_id' => $incoming['city_id'],
            'latitude' => $incoming['latitude'],
            'longitude' => $incoming['longitude'],
            'price_from' => $incoming['price_from'],
            'price_currency' => $incoming['price_currency'],
            'source_url' => $record['source_url'] ?? null,
            'scrape_source' => self::SOURCE,
            'scrape_id' => $record['scrape_id'],
            'scraped_at' => Carbon::now(),
            'claim_status' => 'unclaimed',
            // Nothing from this source goes live unreviewed: the text is
            // namibweb's, the photography is namibweb's, and neither is ours to
            // publish until it has been rewritten and cleared.
            'is_published' => false,
            'accepts_inquiries' => true,
        ]);

        $listing->setTranslation('name', 'en', $incoming['name']);

        if ($this->option('with-descriptions') && $incoming['description']) {
            $listing->setTranslation('description', 'en', $incoming['description']);
            $listing->description_source = ContentSource::Directory;
        }

        $listing->save();

        $written = $this->option('with-descriptions')
            ? $incoming
            : array_merge($incoming, ['description' => null]);

        $this->stagePhotos($listing, $record);
        $this->writeScrapeData($listing, $record, $this->snapshot($written));

        $this->created++;
    }

    /** @param array<string, mixed> $record */
    private function updateListing(Listing $listing, array $record, array $incoming): void
    {
        $stored = $listing->scrape_data['namibweb'] ?? [];
        $previousHashes = $stored['hashes'] ?? [];
        $lastImported = $stored['imported'] ?? [];
        $incomingHashes = $record['hashes'] ?? [];

        $fill = [];

        foreach (self::FIELD_GROUPS as $group => $fields) {
            // Signal 1: upstream silence. Same hash as last import means the
            // page still says what it said, so there is nothing to consider.
            if (($previousHashes[$group] ?? null) === ($incomingHashes[$group] ?? null) && $previousHashes !== []) {
                continue;
            }

            foreach ($fields as $field) {
                $value = $incoming[$field] ?? null;

                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                $current = $this->currentValue($listing, $field);

                // Never overwrite something we hold and they don't improve.
                if ($this->valuesMatch($current, $value)) {
                    continue;
                }

                // Signal 2: our own edits. An empty column is free to fill; a
                // column still holding exactly what we imported is ours to
                // update; anything else was changed on our side since.
                $ours = $lastImported[$field] ?? null;

                if ($current === null || $current === '' || $current === []) {
                    $fill[$field] = $value;

                    continue;
                }

                if ($ours !== null && $this->valuesMatch($current, $ours)) {
                    $fill[$field] = $value;

                    continue;
                }

                $this->conflicts[] = [
                    'listing' => $listing->name,
                    'field' => $field,
                    'ours' => Str::limit((string) $this->stringify($current), 60),
                    'theirs' => Str::limit((string) $this->stringify($value), 60),
                ];
            }
        }

        // Descriptions are opt-in: the default is to keep the upstream text as
        // source material in scrape_data and let a rewrite produce what we
        // publish, rather than putting namibweb's prose on our pages.
        if (! $this->option('with-descriptions')) {
            unset($fill['description']);
        }

        $photosChanged = ($previousHashes['photos'] ?? null) !== ($incomingHashes['photos'] ?? null);

        if (! $fill && ! $photosChanged) {
            $this->unchanged++;
            if (! $this->dry) {
                $this->writeScrapeData($listing, $record, $lastImported);
            }

            return;
        }

        if ($this->dry) {
            $this->newLine();
            $this->line("  [dry] {$listing->name}: ".(
                $fill ? implode(', ', array_keys($fill)) : 'photos only'
            ));
            $this->updated++;

            return;
        }

        $appliedFields = array_keys($fill);

        foreach (['name', 'description'] as $translatable) {
            if (isset($fill[$translatable])) {
                $listing->setTranslation($translatable, 'en', $fill[$translatable]);
                unset($fill[$translatable]);
            }
        }

        if (in_array('description', $appliedFields, true)) {
            $listing->description_source = ContentSource::Directory;
        }

        if ($fill) {
            $listing->fill($fill);
        }

        $listing->scraped_at = Carbon::now();
        $listing->save();

        if ($photosChanged) {
            $this->stagePhotos($listing, $record);
        }

        // Only fields we actually wrote enter the snapshot — a field held back
        // by a conflict must keep its old baseline, or the next run would read
        // our unchanged column as "edited by us" forever.
        $written = $lastImported;
        $listing->refresh();

        foreach ($appliedFields as $field) {
            $written[$field] = $this->currentValue($listing, $field);
        }

        $this->writeScrapeData($listing, $record, $written);
        $this->updated++;
    }

    /**
     * Stages photos for review instead of publishing them.
     *
     * The scraper already downloaded every file once; this copies them onto the
     * media disk and points pending_image/pending_gallery at them. Nothing
     * reaches image/gallery until Listing::approvePendingPhotos() is called from
     * the admin or by the owner.
     */
    private function stagePhotos(Listing $listing, array $record): void
    {
        if ($this->option('no-photos')) {
            return;
        }

        $files = $record['photo_files'] ?? [];
        $photosDir = $this->option('photos-dir') ?: base_path('data/scraped/namibweb_photos');

        $stored = [];

        foreach (array_slice($files, 0, 8) as $file) {
            $local = rtrim((string) $photosDir, '/').'/'.($file['file'] ?? '');
            $bytes = null;

            if (($file['file'] ?? null) && is_readable($local)) {
                $bytes = (string) file_get_contents($local);
            } elseif ($file['url'] ?? null) {
                // The scraper ran elsewhere (a CI runner) and only the JSON came
                // across — fetch the original rather than skipping the photo.
                $response = Http::timeout(20)->get($file['url']);
                if ($response->successful()) {
                    $bytes = $response->body();
                }
            }

            if ($bytes === null || $bytes === '') {
                continue;
            }

            $extension = pathinfo((string) parse_url((string) ($file['url'] ?? ''), PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $key = 'listings/'.$listing->slug.'/namibweb-'.substr((string) ($file['sha256'] ?? md5($bytes)), 0, 12).'.'.$extension;

            Storage::disk('r2')->put($key, $bytes);
            $stored[] = $key;
            $this->photosStaged++;
        }

        if (! $stored) {
            return;
        }

        // pending_photos_source, never photos_source: these are namibweb's
        // photographs, so they are staged as internal reference and
        // Listing::approvePendingPhotos() refuses to promote them. They exist so
        // an admin can see the property while matching it, and so the website
        // crawler has something to visibly improve on.
        $listing->forceFill([
            'pending_image' => $listing->pending_image ?: $stored[0],
            'pending_gallery' => $stored,
            'pending_photos_source' => ContentSource::Directory,
            'photos_attribution' => 'namibweb.com — rights not cleared, reference only',
        ])->save();
    }

    /**
     * Persists provenance: the hashes this import was based on, the exact values
     * it wrote, and the whole upstream record so nothing has to be fetched twice.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $imported
     * @param  array<string, mixed>  $extra
     */
    private function writeScrapeData(Listing $listing, array $record, array $imported, array $extra = []): void
    {
        $data = $listing->scrape_data ?? [];

        $data['namibweb'] = array_merge([
            'scrape_id' => $record['scrape_id'] ?? null,
            'source_url' => $record['source_url'] ?? null,
            'index_url' => $record['index_url'] ?? null,
            'hashes' => $record['hashes'] ?? [],
            'revision' => $record['revision'] ?? null,
            'first_seen_at' => $record['first_seen_at'] ?? null,
            'last_seen_at' => $record['last_seen_at'] ?? null,
            'changed_at' => $record['changed_at'] ?? null,
            'imported' => $imported,
            'imported_at' => Carbon::now()->toIso8601String(),
            // Source material kept whole: the upstream prose we may rewrite, the
            // facts we did not map to a column, and the page as parsed.
            'upstream' => [
                'name' => $record['name'] ?? null,
                'description' => $record['description'] ?? null,
                'region' => $record['region'] ?? null,
                'category' => $record['category'] ?? null,
                'listing_type' => $record['listing_type'] ?? null,
                'facilities' => $record['facilities'] ?? [],
                'emails' => $record['emails'] ?? [],
                'phones' => $record['phones'] ?? [],
                'photos' => $record['photos'] ?? [],
                'social_links' => $record['social_links'] ?? [],
                'geo' => $record['geo'] ?? null,
            ],
            'raw' => $record['raw'] ?? null,
        ], $extra);

        // Bookkeeping only: last_seen_at moving must not make the listing look
        // freshly edited to anything that watches updated_at.
        $timestamps = $listing->timestamps;
        $listing->timestamps = false;
        $listing->forceFill(['scrape_data' => $data])->save();
        $listing->timestamps = $timestamps;
    }

    private function findOrCreatePartner(array $record): Partner
    {
        $name = trim((string) $record['name']);

        $partner = Partner::query()->whereRaw('lower(name) = ?', [Str::lower($name)])->first();

        if ($partner) {
            return $partner;
        }

        return Partner::create([
            'name' => $name,
            'email' => $record['email'] ?? null,
            'phone' => $record['phone'] ?? null,
            'website' => $record['website'] ?? null,
            'facebook' => $record['social_links']['facebook'] ?? null,
            'instagram' => $record['social_links']['instagram'] ?? null,
            'source_url' => $record['source_url'] ?? null,
        ]);
    }

    private function resolveType(array $record): ListingType
    {
        return ListingType::tryFrom((string) ($record['listing_type'] ?? '')) ?? ListingType::Accommodation;
    }

    private function uniqueSlug(string $slug): string
    {
        $slug = $slug ?: 'listing';
        $candidate = $slug;
        $suffix = 2;

        while (Listing::where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function currentValue(Listing $listing, string $field): mixed
    {
        return match ($field) {
            'name' => $listing->getTranslation('name', 'en', false) ?: null,
            'description' => $listing->getTranslation('description', 'en', false) ?: null,
            'latitude', 'longitude', 'price_from' => $listing->{$field} === null ? null : (float) $listing->{$field},
            default => $listing->{$field},
        };
    }

    private function valuesMatch(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            $a = is_array($a) ? $a : [];
            $b = is_array($b) ? $b : [];
            ksort($a);
            ksort($b);

            return $a == $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.0000005;
        }

        return Str::lower(trim((string) $a)) === Str::lower(trim((string) $b));
    }

    private function stringify(mixed $value): string
    {
        return is_array($value) ? (string) json_encode($value) : (string) $value;
    }

    /** @return array<string, mixed> */
    private function snapshot(array $incoming): array
    {
        return array_filter(
            $incoming,
            fn ($value) => $value !== null && $value !== '' && $value !== []
        );
    }

    private function reportConflicts(): void
    {
        if (! $this->conflicts) {
            return;
        }

        $this->newLine();
        $this->warn(count($this->conflicts).' field(s) changed upstream but were edited on our side — left untouched:');
        $this->table(['Listing', 'Field', 'Ours', 'namibweb'], array_map(
            fn ($c) => [$c['listing'], $c['field'], $c['ours'], $c['theirs']],
            array_slice($this->conflicts, 0, 40)
        ));

        if (count($this->conflicts) > 40) {
            $this->line('  … and '.(count($this->conflicts) - 40).' more');
        }
    }

    private function reportVanished(): void
    {
        if (! $this->vanished) {
            return;
        }

        $this->newLine();
        $this->warn(count($this->vanished).' listing(s) no longer on namibweb.com — flagged, not unpublished:');
        foreach (array_slice($this->vanished, 0, 20) as $entry) {
            $this->line("  · {$entry['listing']} ({$entry['url']})");
        }
    }
}
