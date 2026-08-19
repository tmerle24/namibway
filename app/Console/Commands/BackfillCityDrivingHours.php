<?php

namespace App\Console\Commands;

use App\Enums\PlaceType;
use App\Models\City;
use App\Services\Routing\OsrmDrivingTimeService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Computes and stores real driving hours between every pair of Namibia's
 * larger places — the bigger settlements plus every tourism area (park,
 * reserve, landmark), which is exactly where a lodge stands when it stands
 * nowhere near a town, plus anywhere a published listing actually is. Which
 * types count is PlaceType::inDrivingMatrix(); the ~65 small village/
 * settlement/private_town rows are otherwise excluded, near-zero chance of
 * ever hosting a bookable Listing; see ItineraryService::drivingHours(),
 * which falls back to the existing region-level table for anything outside
 * this matrix, so nothing is left unvalidated even for an excluded
 * settlement.
 *
 * Requires namibway:backfill-city-coordinates to have already filled lat/lng
 * for every in-scope city — refuses to run otherwise rather than silently
 * writing a matrix with holes. Safe to rerun: upserts on the
 * [city_a_id, city_b_id] unique constraint.
 */
class BackfillCityDrivingHours extends Command
{
    protected $signature = 'namibway:backfill-city-driving-hours
                            {--dry-run : Compute and summarize without writing to DB}';

    protected $description = 'Compute real place-to-place driving hours via OSRM for towns, parks and reserves and store them for Kaia\'s driving-time safety check';

    /**
     * The places worth a row in the matrix: the types that plausibly host a
     * listing, plus — regardless of type — anywhere that actually does. The
     * second half is what keeps Sesriem, Solitaire, Palmwag and Aus in: they
     * are officially settlements, the class this excludes wholesale, and they
     * are also where a Sossusvlei or Namib traveler sleeps.
     *
     * @return Builder<City>
     */
    private static function inScope(): Builder
    {
        return City::query()->where(fn ($query) => $query
            ->whereIn('type', PlaceType::drivingMatrixValues())
            ->orWhereHas('listings', fn ($listings) => $listings->where('is_published', true)));
    }

    public function handle(OsrmDrivingTimeService $osrm): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $missingCoordinates = self::inScope()
            ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng'))
            ->count();

        if ($missingCoordinates > 0) {
            $this->error("{$missingCoordinates} in-scope place(s) still have no coordinates — run namibway:backfill-city-coordinates first.");

            return self::FAILURE;
        }

        $cities = self::inScope()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'name', 'lat', 'lng']);

        if ($cities->count() < 2) {
            $this->info('Fewer than 2 in-scope places with coordinates — nothing to compute.');

            return self::SUCCESS;
        }

        $this->info("Computing driving hours between {$cities->count()} cities via OSRM...");

        $pairs = $osrm->durationMatrix($cities);

        $possiblePairs = intdiv($cities->count() * ($cities->count() - 1), 2);
        $unroutable = $possiblePairs - count($pairs);

        if ($pairs === []) {
            $this->error('OSRM returned no usable driving times — check connectivity/logs, nothing written.');

            return self::FAILURE;
        }

        if (! $dry) {
            $now = now();

            DB::table('city_driving_hours')->upsert(
                array_map(fn (array $pair) => [...$pair, 'created_at' => $now, 'updated_at' => $now], $pairs),
                ['city_a_id', 'city_b_id'],
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
