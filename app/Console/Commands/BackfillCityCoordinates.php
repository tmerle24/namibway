<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Services\Enrichment\OsmLocationFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill for City rows with no lat/lng — every one of the 105
 * seeded cities starts out unset (CitySeeder never geocodes). Needed before
 * namibway:backfill-place-driving-hours can compute a real OSRM matrix, and
 * before KaiaController::regionCoords()/SavedPlanController::pdf() can place
 * a city-precision marker on the trip map instead of falling back to a
 * region/destination centroid.
 *
 * Since the 2026-08-23 city/place split it finishes by copying what it found
 * onto the places those cities are, so a place created for a town that had no
 * coordinates yet stops being unroutable without anybody having to know the
 * two tables exist. That copy is why running this command is the documented
 * fix when namibway:backfill-place-driving-hours refuses over a coordinateless
 * place.
 *
 * Reuses OsmLocationFinder as-is (same free Nominatim lookup + 1req/s
 * throttle already used for Listing geocoding) — City just has no free-text
 * address to geocode against, so the parent region's name is passed in the
 * 'address' slot purely to disambiguate small settlements ("Rundu Kavango
 * East Namibia" beats "Rundu Namibia" alone). Safe to interrupt and rerun:
 * already-filled cities are excluded from the query every time.
 */
class BackfillCityCoordinates extends Command
{
    protected $signature = 'namibway:backfill-city-coordinates
                            {--limit= : Only process this many cities (for a test run)}
                            {--dry-run : Show what would be geocoded without writing to DB}';

    protected $description = 'Fill missing lat/lng for cities with no coordinates, via Nominatim geocoding';

    public function handle(OsmLocationFinder $osmFinder): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if ($dry) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $query = City::whereNull('lat')->whereNull('lng')->with('region:id,name');

        $total = $query->count();

        if ($total === 0) {
            $this->info('No cities missing coordinates — nothing to backfill.');

            return self::SUCCESS;
        }

        $toProcess = $limit ? min($limit, $total) : $total;
        $this->info("Found {$total} cities missing coordinates".($limit ? ", processing {$toProcess}." : '.'));

        $geocoded = 0;
        $notFound = 0;
        $bar = $this->output->createProgressBar($toProcess);
        $bar->start();

        $query->select(['id', 'name', 'region_id'])
            ->chunkById(50, function ($cities) use (&$geocoded, &$notFound, &$toProcess, $dry, $osmFinder, $bar) {
                foreach ($cities as $city) {
                    if ($toProcess <= 0) {
                        return false;
                    }

                    // City's own columns are 'lat'/'lng' (unlike Listing's
                    // 'latitude'/'longitude'), so the finder's return keys are
                    // remapped below rather than reused directly.
                    $facts = $osmFinder->fillMissingCoordinates(['address' => $city->region->name], $city->name);

                    if (! isset($facts['latitude'], $facts['longitude'])) {
                        $notFound++;
                        $toProcess--;
                        $bar->advance();

                        continue;
                    }

                    $geocoded++;

                    if (! $dry) {
                        $city->forceFill(['lat' => $facts['latitude'], 'lng' => $facts['longitude']])->saveQuietly();
                    }

                    $toProcess--;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $synced = $dry ? 0 : self::syncPlaceCoordinates();

        $this->table(
            ['Geocoded', 'Not found', 'Places updated'],
            [[$geocoded, $notFound, $synced]],
        );

        return self::SUCCESS;
    }

    /**
     * Copies a city's coordinates onto the place it is, for places still
     * missing them. Only ever fills a blank — a place whose coordinates were
     * set by hand (a park's centre is not its nearest town) stays as it is.
     */
    private static function syncPlaceCoordinates(): int
    {
        return DB::update('
            UPDATE places SET lat = cities.lat, lng = cities.lng, updated_at = NOW()
            FROM cities
            WHERE cities.place_id = places.id
              AND (places.lat IS NULL OR places.lng IS NULL)
              AND cities.lat IS NOT NULL
              AND cities.lng IS NOT NULL
        ');
    }
}
