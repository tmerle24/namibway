<?php

namespace Tests\Feature\Commands;

use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The command requires every in-scope (municipality/town/community) city to
 * already have lat/lng — a freshly migrated DB has none (see
 * create_cities_table.php), so it fails by default without any setup; these
 * tests bulk-fill coordinates for that scenario instead of relying on the
 * real namibway:backfill-city-coordinates command (covered separately).
 */
class BackfillCityDrivingHoursCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Fakes OSRM's Table API for any chunk size/shape durationMatrix() requests. */
    private function fakeOsrmTable(int $seconds): void
    {
        Http::fake(function ($request) use ($seconds) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $sources = explode(';', $query['sources']);
            $destinations = explode(';', $query['destinations']);

            $durations = [];

            foreach ($sources as $si => $srcIdx) {
                foreach ($destinations as $di => $dstIdx) {
                    $durations[$si][$di] = $srcIdx === $dstIdx ? 0 : $seconds;
                }
            }

            return Http::response(['durations' => $durations], 200);
        });
    }

    public function test_fails_when_an_in_scope_city_is_missing_coordinates(): void
    {
        // Default state: no city has lat/lng yet.
        $this->artisan('namibway:backfill-city-driving-hours')->assertFailed();

        $this->assertSame(0, DB::table('city_driving_hours')->count());
    }

    public function test_dry_run_computes_but_writes_nothing(): void
    {
        City::whereIn('type', ['municipality', 'town', 'community'])->update(['lat' => -22.0, 'lng' => 17.0]);
        $this->fakeOsrmTable(7200);

        $this->artisan('namibway:backfill-city-driving-hours', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('city_driving_hours')->count());
    }

    public function test_computes_and_upserts_real_pairs(): void
    {
        $inScope = City::whereIn('type', ['municipality', 'town', 'community']);
        $inScope->update(['lat' => -22.0, 'lng' => 17.0]);
        $expectedPairs = intdiv($inScope->count() * ($inScope->count() - 1), 2);

        // Captured by reference so the rerun below can change what the same
        // registered fake returns — Http::fake() merges callbacks rather than
        // replacing them, so a second Http::fake() call wouldn't override this one.
        $seconds = 7200;
        Http::fake(function ($request) use (&$seconds) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $sources = explode(';', $query['sources']);
            $destinations = explode(';', $query['destinations']);

            $durations = [];

            foreach ($sources as $si => $srcIdx) {
                foreach ($destinations as $di => $dstIdx) {
                    $durations[$si][$di] = $srcIdx === $dstIdx ? 0 : $seconds;
                }
            }

            return Http::response(['durations' => $durations], 200);
        });

        $this->artisan('namibway:backfill-city-driving-hours')->assertSuccessful();

        $this->assertSame($expectedPairs, DB::table('city_driving_hours')->count());

        $windhoek = City::where('slug', 'windhoek')->firstOrFail();
        $swakopmund = City::where('slug', 'swakopmund')->firstOrFail();

        $row = DB::table('city_driving_hours')
            ->where('city_a_id', min($windhoek->id, $swakopmund->id))
            ->where('city_b_id', max($windhoek->id, $swakopmund->id))
            ->first();

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(2.0, $row->hours, 0.001);

        // Rerun with a different duration — upsert should update in place, not duplicate.
        $seconds = 3600;
        $this->artisan('namibway:backfill-city-driving-hours')->assertSuccessful();

        $this->assertSame($expectedPairs, DB::table('city_driving_hours')->count());

        $updated = DB::table('city_driving_hours')
            ->where('city_a_id', min($windhoek->id, $swakopmund->id))
            ->where('city_b_id', max($windhoek->id, $swakopmund->id))
            ->first();

        $this->assertEqualsWithDelta(1.0, $updated->hours, 0.001);
    }
}
