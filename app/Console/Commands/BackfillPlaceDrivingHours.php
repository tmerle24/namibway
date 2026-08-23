<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Services\Routing\OsrmDrivingTimeService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Computes and stores real driving hours between every pair of places.
 *
 * A place exists precisely because it is somewhere a traveler goes — the towns
 * they plan around plus every park, reserve and landmark — so the matrix is
 * simply every place, with no type filter to keep in step with anything. That
 * is the payoff of the 2026-08-23 split: while parks lived in `cities` next to
 * ~65 villages nobody stays in, this command needed a rule for which rows
 * counted, and the rule had to be repeated in the migration, the tests and
 * Kaia.
 *
 * Requires coordinates on every place — refuses to run otherwise rather than
 * silently writing a matrix with holes. Places inherit their coordinates from
 * the city they were created from, so namibway:backfill-city-coordinates is
 * still what fills the blanks, followed by a re-run of this command.
 *
 * Safe to rerun: upserts on the [place_a_id, place_b_id] unique constraint.
 */
class BackfillPlaceDrivingHours extends Command
{
    protected $signature = 'namibway:backfill-place-driving-hours
                            {--dry-run : Compute and summarize without writing to DB}';

    protected $description = 'Compute real place-to-place driving hours via OSRM for towns, parks and reserves and store them for Kaia\'s driving-time safety check';

    /**
     * The places worth a row in the matrix: the types that plausibly host a
     * listing, plus — regardless of type — anywhere that actually does. The
     * second half is what keeps Sesriem, Solitaire, Palmwag and Aus in: they
     * are officially settlements, the class this excludes wholesale, and they
     * are also where a Sossusvlei or Namib traveler sleeps.
     *
     * @return Builder<Place>
     */
    private static function inScope(): Builder
    {
        return Place::query();
    }

    public function handle(OsrmDrivingTimeService $osrm): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $missingCoordinates = self::inScope()
            ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng'))
            ->orderBy('slug')
            ->get(['id', 'name', 'slug', 'type']);

        if ($missingCoordinates->isNotEmpty()) {
            // Naming them matters: the fix is different per row. Most are
            // geocodable, and the ones OpenStreetMap does not know have to be
            // typed in by a person — and until 2026-08-23 this message sent
            // people to the *city* geocoder, which cannot help a national park.
            $this->error($missingCoordinates->count().' place(s) have no coordinates, so the matrix would have holes. Nothing written.');
            $this->newLine();
            $this->table(
                ['Place', 'Type'],
                $missingCoordinates->map(fn (Place $place): array => [
                    $place->getTranslation('name', 'en', useFallbackLocale: true),
                    $place->type->value,
                ])->all(),
            );
            $this->newLine();
            $this->line('Run <options=bold>namibway:backfill-place-coordinates</> to geocode them.');
            $this->line('Anything it cannot find has to be set by hand under Settings → Places.');

            return self::FAILURE;
        }

        $places = self::inScope()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'name', 'lat', 'lng']);

        if ($places->count() < 2) {
            $this->info('Fewer than 2 places with coordinates — nothing to compute.');

            return self::SUCCESS;
        }

        $this->info("Computing driving hours between {$places->count()} places via OSRM...");

        $pairs = $osrm->durationMatrix($places);

        $possiblePairs = intdiv($places->count() * ($places->count() - 1), 2);
        $unroutable = $possiblePairs - count($pairs);

        if ($pairs === []) {
            $this->error('OSRM returned no usable driving times — check connectivity/logs, nothing written.');

            return self::FAILURE;
        }

        if (! $dry) {
            $now = now();

            DB::table('place_driving_hours')->upsert(
                array_map(fn (array $pair) => [...$pair, 'created_at' => $now, 'updated_at' => $now], $pairs),
                ['place_a_id', 'place_b_id'],
                ['hours', 'updated_at'],
            );
        }

        $hours = array_column($pairs, 'hours');

        $this->table(
            ['Pairs computed', 'Unroutable/skipped', 'Min hours', 'Max hours', 'Avg hours'],
            [[
                count($pairs),
                $unroutable,
                min($hours),
                max($hours),
                round(array_sum($hours) / count($hours), 2),
            ]],
        );

        return self::SUCCESS;
    }
}
