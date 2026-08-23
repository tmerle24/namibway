<?php

namespace App\Console\Commands;

use App\Enums\PlaceType;
use App\Models\Place;
use App\Services\Enrichment\OsmLocationFinder;
use Illuminate\Console\Command;

/**
 * Geocodes places that have no coordinates.
 *
 * A gap the 2026-08-23 city/place split opened and this closes: parks,
 * reserves and landmarks used to be rows in `cities`, so
 * namibway:backfill-city-coordinates geocoded them along with everything else.
 * They are places now, that command only knows cities, and the copy it does at
 * the end only fills a place from the city it *is* — which a national park is
 * not. So seventeen tourism places sat with no coordinates and nothing to fill
 * them, which keeps them out of the driving matrix and off the map.
 *
 * Same Nominatim lookup and one-request-per-second throttle as the city
 * command, and the same two passes findLocationFacts uses for listings: with
 * the region as context, then without. The region disambiguates a name like
 * "Waterberg", and it *prevents* a match for Etosha National Park, which
 * spans three regions and is filed under none of them — the first run of this
 * command found four places out of six and missed exactly that one.
 *
 * Only ever fills a blank. A coordinate somebody set by hand is a decision —
 * the centre of a park is a judgement call, not a lookup — and this must not
 * second-guess it.
 */
class BackfillPlaceCoordinates extends Command
{
    protected $signature = 'namibway:backfill-place-coordinates
                            {--limit= : Only process this many places}
                            {--dry-run : Show what would be geocoded without writing}';

    protected $description = 'Fill missing lat/lng for places with no coordinates, via Nominatim geocoding';

    public function handle(OsmLocationFinder $osm): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($dry) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $query = Place::query()
            ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng'))
            ->with('region:id,name')
            ->orderBy('slug');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $places = $query->get();

        if ($places->isEmpty()) {
            $this->info('No places missing coordinates — nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info("Geocoding {$places->count()} places...");

        $bar = $this->output->createProgressBar($places->count());
        $bar->start();

        $geocoded = 0;
        $notFound = [];

        foreach ($places as $place) {
            $name = $place->getTranslation('name', 'en', useFallbackLocale: true);

            $facts = self::locate($osm, $name, (string) $place->region?->name);

            if (! isset($facts['latitude'], $facts['longitude'])) {
                $notFound[] = [$name, $place->type->value];
                $bar->advance();

                continue;
            }

            $geocoded++;

            if (! $dry) {
                $place->forceFill(['lat' => $facts['latitude'], 'lng' => $facts['longitude']])->saveQuietly();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($notFound !== []) {
            $this->warn('No answer from OpenStreetMap for these — set them by hand under Settings → Places:');
            $this->table(['Place', 'Type'], $notFound);
        }

        $stillMissing = Place::query()
            ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng'))
            ->where('type', '!=', PlaceType::Town->value)
            ->count();

        $this->table(
            ['Geocoded', 'Not found', 'Tourism places still without coordinates'],
            [[$geocoded, count($notFound), $dry ? '(dry run)' : $stillMissing]],
        );

        return self::SUCCESS;
    }

    /**
     * Two passes: with the region as context, then the bare name.
     *
     * @return array{latitude?: float, longitude?: float}
     */
    private static function locate(OsmLocationFinder $osm, string $name, string $region): array
    {
        foreach (array_unique([$region, '']) as $context) {
            $facts = $osm->fillMissingCoordinates(['address' => $context], $name);

            if (isset($facts['latitude'], $facts['longitude'])) {
                return $facts;
            }
        }

        return [];
    }
}
