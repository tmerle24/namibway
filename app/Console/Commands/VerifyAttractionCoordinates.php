<?php

namespace App\Console\Commands;

use App\Models\Attraction;
use App\Services\Enrichment\OsmLocationFinder;
use Illuminate\Console\Command;

/**
 * Checks every attraction's coordinates against OpenStreetMap and reports how
 * far apart the two answers are.
 *
 * The seeded corpus (2026_08_23_160000_seed_attractions) was written from
 * knowledge rather than read off a map. Its geography is right — nothing is in
 * the wrong region — but a pin two kilometres out is a wrong turn on a gravel
 * road, and checking 47 of them by hand is the kind of job nobody finishes. So
 * this does the looking-up and only asks a person about the ones that disagree.
 *
 * **Report only by default, and that is the point.** Nominatim is often worse
 * than we are for a rock-art site or a farm gate: it will happily return the
 * centre of the nearest town, or a hotel that borrowed the landmark's name. A
 * disagreement means "somebody look", not "OSM is right". `--apply` exists for
 * the case where you have looked and OSM plainly wins, and it refuses to touch
 * anything below the threshold.
 *
 * Rate-limited to Nominatim's one request per second by OsmLocationFinder
 * itself, so a full run over the corpus takes about a minute.
 */
class VerifyAttractionCoordinates extends Command
{
    protected $signature = 'namibway:verify-attraction-coordinates
                            {--threshold=3 : Report anything further apart than this many kilometres}
                            {--limit= : Only check this many attractions}
                            {--apply : Overwrite our coordinates with OSMs for rows over the threshold}';

    protected $description = "Compare each attraction's coordinates against OpenStreetMap and list the ones that disagree";

    public function handle(OsmLocationFinder $osm): int
    {
        $threshold = (float) $this->option('threshold');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $apply = (bool) $this->option('apply');

        $query = Attraction::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->with(['place', 'city'])
            ->orderBy('slug');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $attractions = $query->get();

        if ($attractions->isEmpty()) {
            $this->info('No attractions with coordinates to check.');

            return self::SUCCESS;
        }

        if ($apply) {
            $this->warn("--apply: rows more than {$threshold} km out will be overwritten with OpenStreetMap's answer.");
        }

        $this->info("Checking {$attractions->count()} attractions against OpenStreetMap...");

        $bar = $this->output->createProgressBar($attractions->count());
        $bar->start();

        $disagreements = [];
        $notFound = [];
        $agreed = 0;
        $applied = 0;

        foreach ($attractions as $attraction) {
            $name = $attraction->getTranslation('name', 'en', useFallbackLocale: true);

            // The nearest place narrows an otherwise hopeless query: there is
            // more than one "Organ Pipes" on Earth, and only one near
            // Twyfelfontein.
            $context = (string) (($attraction->place ?? $attraction->city)?->name);

            $found = $osm->fillMissingCoordinates(['address' => $context], $name);

            if (! isset($found['latitude'], $found['longitude'])) {
                $notFound[] = [$name, $context ?: '—'];
                $bar->advance();

                continue;
            }

            $km = self::distanceKm(
                (float) $attraction->lat,
                (float) $attraction->lng,
                (float) $found['latitude'],
                (float) $found['longitude'],
            );

            if ($km <= $threshold) {
                $agreed++;
                $bar->advance();

                continue;
            }

            $disagreements[] = [
                $name,
                sprintf('%.4f, %.4f', $attraction->lat, $attraction->lng),
                sprintf('%.4f, %.4f', $found['latitude'], $found['longitude']),
                sprintf('%.1f km', $km),
            ];

            if ($apply) {
                $attraction->forceFill([
                    'lat' => $found['latitude'],
                    'lng' => $found['longitude'],
                ])->saveQuietly();

                $applied++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($disagreements !== []) {
            $this->warn('These disagree by more than '.$threshold.' km — look at each one before trusting either value:');
            $this->table(['Attraction', 'Ours', 'OpenStreetMap', 'Apart'], $disagreements);
        }

        if ($notFound !== []) {
            $this->newLine();
            $this->line('OpenStreetMap had no answer for these, which is normal for a farm gate or a rock-art site — our value stands:');
            $this->table(['Attraction', 'Searched near'], $notFound);
        }

        $this->newLine();
        $this->table(
            ['Checked', "Within {$threshold} km", 'Disagreed', 'Not on OSM', 'Overwritten'],
            [[$attractions->count(), $agreed, count($disagreements), count($notFound), $applied]],
        );

        return self::SUCCESS;
    }

    /**
     * Great-circle distance in kilometres. Plain haversine: at these distances
     * the difference from a proper geodesic is metres, and the question here is
     * "is this pin in the wrong place", not surveying.
     */
    private static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
