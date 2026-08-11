<?php

namespace App\Console\Commands;

use App\Models\Listing;
use Illuminate\Console\Command;

/**
 * Fills coordinates from namibweb's camping GPS index.
 *
 * namibweb publishes one page listing every camping site with its position, and
 * it is by far the best locator this source has: only 46 of 1165 Namibian
 * listings state coordinates on their own detail page, while that index carries
 * 252. Coordinates matter more here than anywhere else in the pipeline —
 * city_id comes from nearest-city GPS matching, and Kaia cannot place a listing
 * in a route without them.
 *
 * Matching is by link, never by name. The index links most entries to the same
 * detail page the listing was scraped from, so the href identifies an entry
 * exactly, and the scraper records it as scrape_id. Names do not survive this
 * data: one entry is called just "Camp", many carry a "Camping " prefix the
 * listing does not, and "Palmwag Lodge" appears twice with positions 35 km
 * apart. Entries the page does not link are reported, not guessed at.
 *
 * Writes only where both latitude and longitude are empty. A bulk backfill over
 * existing non-null data is destructive — namibway:backfill-listing-cities once
 * overwrote correct city_id values and needed a restore from backup — so this
 * one cannot overwrite anything, whatever the source says.
 */
class BackfillNamibwebCoordinates extends Command
{
    protected $signature = 'namibway:backfill-namibweb-coordinates
                            {--file= : Path to namibweb_camping_coordinates.json}
                            {--dry-run : Report what would be filled without writing}';

    protected $description = 'Fill missing listing coordinates from namibweb\'s camping GPS index, matched by link';

    public function handle(): int
    {
        $path = (string) ($this->option('file') ?: base_path('data/scraped/namibweb_camping_coordinates.json'));

        if (! is_readable($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        $entries = $payload['entries'] ?? null;

        if (! is_array($entries)) {
            $this->error('Unexpected JSON shape — expected {"entries": [...]}');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        if ($dry) {
            $this->warn('DRY RUN — nothing will be written');
        }

        // Two entries sharing a scrape_id with different positions is a
        // contradiction in the source, not something to pick a winner from:
        // "Camping Palmwag Lodge" and "Palmwag Lodge" both link palmwagl.htm
        // and sit 35 km apart. Report them and touch neither.
        $byScrapeId = [];
        $unlinked = 0;
        foreach ($entries as $entry) {
            $scrapeId = $entry['scrape_id'] ?? null;
            if (! $scrapeId) {
                $unlinked++;

                continue;
            }
            $byScrapeId[$scrapeId][] = $entry;
        }

        $conflicts = array_filter($byScrapeId, fn (array $group) => $this->isContradictory($group));
        $usable = array_diff_key($byScrapeId, $conflicts);

        $filled = $alreadyHad = $noListing = 0;

        foreach ($usable as $scrapeId => $group) {
            $entry = $group[0];
            $listing = $this->findListing((string) $scrapeId);

            if (! $listing) {
                $noListing++;

                continue;
            }

            if ($listing->latitude !== null || $listing->longitude !== null) {
                $alreadyHad++;

                continue;
            }

            $this->line(sprintf(
                '  %s → %.6f, %.6f  (%s)',
                $listing->slug, $entry['latitude'], $entry['longitude'], $entry['name'] ?? ''
            ));

            if (! $dry) {
                $listing->forceFill([
                    'latitude' => $entry['latitude'],
                    'longitude' => $entry['longitude'],
                ])->save();
            }

            $filled++;
        }

        $this->newLine();
        $this->info(sprintf('%d filled, %d already had coordinates, %d had no listing', $filled, $alreadyHad, $noListing));
        $this->line(sprintf('%d entries the index does not link — no safe way to match them', $unlinked));

        if ($conflicts !== []) {
            $this->newLine();
            $this->warn(sprintf('%d contradictory entries, left untouched:', count($conflicts)));
            foreach ($conflicts as $scrapeId => $group) {
                $this->line("  {$scrapeId}:");
                foreach ($group as $entry) {
                    $this->line(sprintf(
                        '    %.6f, %.6f  %s',
                        $entry['latitude'], $entry['longitude'], $entry['name'] ?? ''
                    ));
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Whether a group of entries disagrees about where the place is.
     *
     * Duplicates that agree are harmless — the same camp listed twice under two
     * spellings. Roughly 100 m of tolerance, well inside the precision decimal
     * minutes give and well below any distance that would matter for routing.
     *
     * @param  array<int, array<string, mixed>>  $group
     */
    private function isContradictory(array $group): bool
    {
        foreach ($group as $entry) {
            if (abs((float) $entry['latitude'] - (float) $group[0]['latitude']) > 0.001
                || abs((float) $entry['longitude'] - (float) $group[0]['longitude']) > 0.001) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same two-stage lookup the importer uses: our own rows first, then rows
     * another source owns that recorded our id without taking over.
     */
    private function findListing(string $scrapeId): ?Listing
    {
        return Listing::query()
            ->where('scrape_source', ImportNamibweb::SOURCE)
            ->where('scrape_id', $scrapeId)
            ->first()
            ?? Listing::query()
                ->where('scrape_data->namibweb->scrape_id', $scrapeId)
                ->first();
    }
}
