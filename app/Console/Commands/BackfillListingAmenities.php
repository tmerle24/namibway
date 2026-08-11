<?php

namespace App\Console\Commands;

use App\Enums\AmenityScope;
use App\Models\Amenity;
use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Turn the free-text `facilities` a scraper collected into catalogue entries.
 *
 * A command with a dry run rather than something a migration does, because
 * bulk-writing over existing rows is the class of operation that cost this
 * project a day once already (CLAUDE.md, "Data-loss lesson"). Nothing here
 * deletes: `facilities` is left exactly as it is, as the record of what a
 * source claimed, and the catalogue entries are added beside it.
 *
 * **A property that already has chosen amenities is skipped entirely.** Those
 * were picked by somebody who owns the place; a scraper's guess does not get
 * to add to them, let alone correct them.
 *
 * Two kinds of match, in order:
 *
 * 1. The eleven keys `scrape_namibweb.py` produces, mapped by hand. They are a
 *    closed set and the mapping is exact, which is why this is worth doing at
 *    all.
 * 2. Anything else — the AI extractor writes free prose off a website — is
 *    matched on a normalised name against the property side of the catalogue.
 *    A miss is reported, never guessed at.
 */
class BackfillListingAmenities extends Command
{
    protected $signature = 'amenities:backfill-listings
                            {--dry-run : Report what would change and write nothing}
                            {--limit=0 : Stop after this many listings}';

    protected $description = 'Map scraped facilities onto the shared amenity catalogue';

    /**
     * The scraper's own keys. Exact, because the set is closed — see
     * _FACILITY_KEYWORDS in scripts/scrape_namibweb.py.
     *
     * @var array<string, string>
     */
    private const SCRAPER_KEYS = [
        'pool' => 'swimming_pool',
        'restaurant' => 'restaurant',
        'bar' => 'bar',
        'wifi' => 'property_wifi',
        'air_conditioning' => 'air_conditioning',
        'game_drives' => 'game_drives',
        'airstrip' => 'airstrip',
        'conference' => 'conference_facilities',
        'wheelchair' => 'wheelchair_accessible',
        'pets' => 'pets_allowed',
        'credit_cards' => 'credit_cards',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $catalogue = Amenity::catalogue(AmenityScope::Property);
        $byCode = $catalogue->keyBy('code');
        $byName = $catalogue->keyBy(fn (Amenity $amenity) => $this->normalise($amenity->name));

        $query = Listing::query()
            ->whereNotNull('facilities')
            ->whereDoesntHave('amenities')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $touched = 0;
        $attached = 0;
        /** @var array<string, int> $unmatched */
        $unmatched = [];

        foreach ($query->cursor() as $listing) {
            $ids = [];

            foreach ($listing->facilities ?? [] as $facility) {
                if (! is_string($facility) || $facility === '') {
                    continue;
                }

                $key = $this->normalise($facility);
                $code = self::SCRAPER_KEYS[$facility] ?? self::SCRAPER_KEYS[$key] ?? null;

                $amenity = $code !== null
                    ? $byCode->get($code)
                    : ($byCode->get($key) ?? $byName->get($key));

                if (! $amenity instanceof Amenity) {
                    $unmatched[$facility] = ($unmatched[$facility] ?? 0) + 1;

                    continue;
                }

                $ids[$amenity->id] = $amenity->id;
            }

            if ($ids === []) {
                continue;
            }

            $touched++;
            $attached += count($ids);

            if (! $dryRun) {
                $listing->amenities()->syncWithoutDetaching(array_values($ids));
            }
        }

        $this->info(($dryRun ? 'Would attach ' : 'Attached ')
            .$attached.' '.Str::plural('amenity', $attached)
            .' across '.$touched.' '.Str::plural('listing', $touched).'.');

        if ($unmatched !== []) {
            arsort($unmatched);

            $this->newLine();
            $this->warn('Not in the catalogue — left as free text:');

            foreach (array_slice($unmatched, 0, 40, true) as $facility => $count) {
                $this->line(sprintf('  %-40s %d', Str::limit($facility, 38), $count));
            }

            if (count($unmatched) > 40) {
                $this->line('  … and '.(count($unmatched) - 40).' more.');
            }

            $this->newLine();
            $this->line('Anything worth having is one more row in Content → Amenities, then run this again.');
        }

        return self::SUCCESS;
    }

    /** "Wi-Fi", "wifi" and "WI FI" are the same word to a person. */
    private function normalise(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($value)) ?? '', '_');
    }
}
