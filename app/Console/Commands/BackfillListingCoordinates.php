<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\Enrichment\CoordinateTextParser;
use App\Services\Enrichment\OsmLocationFinder;
use Illuminate\Console\Command;

/**
 * One-time backfill for listings that have an address but no lat/lng — the same gap
 * ListingController::show() already papers over per-request (parse coordinate text, else
 * geocode via Nominatim and persist). Running this ahead of time means visitors don't pay
 * the geocoding latency on first view, and listings nobody happens to visit still end up
 * with coordinates. Safe to interrupt and rerun: already-filled listings are excluded from
 * the query every time.
 *
 * Nominatim's usage policy caps the public endpoint at 1 request/second (enforced by
 * OsmLocationFinder's own throttle) — for a few thousand addresses this command will run
 * for hours, not minutes. That's expected; let it run in the background.
 */
class BackfillListingCoordinates extends Command
{
    protected $signature = 'namibway:backfill-listing-coordinates
                            {--limit= : Only process this many listings (for a test run)}
                            {--dry-run : Show what would be geocoded without writing to DB}';

    protected $description = 'Fill missing latitude/longitude for listings that have an address, via coordinate-text parsing or Nominatim geocoding';

    public function handle(OsmLocationFinder $osmFinder): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if ($dry) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $query = Listing::whereNull('latitude')
            ->whereNull('longitude')
            ->whereNotNull('address')
            ->where('address', '!=', '');

        $total = $query->count();

        if ($total === 0) {
            $this->info('No listings with an address but no coordinates — nothing to backfill.');

            return self::SUCCESS;
        }

        $toProcess = $limit ? min($limit, $total) : $total;
        $this->info("Found {$total} listings with an address but no coordinates".($limit ? ", processing {$toProcess}." : '.'));

        $parsed = 0;
        $geocoded = 0;
        $notFound = 0;
        $bar = $this->output->createProgressBar($toProcess);
        $bar->start();

        $query->select(['id', 'name', 'address'])
            ->chunkById(200, function ($listings) use (&$parsed, &$geocoded, &$notFound, &$toProcess, $dry, $osmFinder, $bar) {
                foreach ($listings as $listing) {
                    if ($toProcess <= 0) {
                        return false;
                    }

                    $coordinates = CoordinateTextParser::parse($listing->address);

                    if ($coordinates !== null) {
                        $parsed++;
                        [$lat, $lng] = $coordinates;
                    } else {
                        $facts = $osmFinder->fillMissingCoordinates(['address' => $listing->address], $listing->name);

                        if (! isset($facts['latitude'], $facts['longitude'])) {
                            $notFound++;
                            $toProcess--;
                            $bar->advance();

                            continue;
                        }

                        $geocoded++;
                        $lat = $facts['latitude'];
                        $lng = $facts['longitude'];
                    }

                    if (! $dry) {
                        $listing->forceFill(['latitude' => $lat, 'longitude' => $lng])->saveQuietly();
                    }

                    $toProcess--;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->table(
            ['Parsed from address text', 'Geocoded via Nominatim', 'Not found'],
            [[$parsed, $geocoded, $notFound]],
        );

        return self::SUCCESS;
    }
}
