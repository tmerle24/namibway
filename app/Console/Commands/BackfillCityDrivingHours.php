<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Services\Routing\OsrmDrivingTimeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Computes and stores real driving hours between every pair of Namibia's
 * larger cities (municipality/town/community — the ~65 smaller village/
 * settlement/private_town rows are excluded, near-zero chance of ever
 * hosting a bookable Listing; see ItineraryService::drivingHours(), which
 * falls back to the existing region-level table for anything outside this
 * matrix, so nothing is left unvalidated even for an excluded settlement).
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

    protected $description = 'Compute real city-to-city driving hours via OSRM for cities/towns and store them for Kaia\'s driving-time safety check';

    private const IN_SCOPE_TYPES = ['municipality', 'town', 'community'];

    public function handle(OsrmDrivingTimeService $osrm): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $missingCoordinates = City::whereIn('type', self::IN_SCOPE_TYPES)
            ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng'))
            ->count();

        if ($missingCoordinates > 0) {
            $this->error("{$missingCoordinates} in-scope city/cities still have no coordinates — run namibway:backfill-city-coordinates first.");

            return self::FAILURE;
        }

        $cities = City::whereIn('type', self::IN_SCOPE_TYPES)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'name', 'lat', 'lng']);

        if ($cities->count() < 2) {
            $this->info('Fewer than 2 in-scope cities with coordinates — nothing to compute.');

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
