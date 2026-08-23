<?php

namespace Tests\Feature\Commands;

use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every place is in the matrix — that is what a place is for — so the command
 * has no scope rule of its own to test since 2026-08-23. What it does have is a
 * refusal: a place with no coordinates stops the run rather than leaving a
 * matrix with holes. A freshly migrated DB has no coordinates at all (they are
 * inherited from cities, which are seeded without them), so these tests bulk-
 * fill them instead of leaning on the real backfill command, covered separately.
 */
class BackfillPlaceDrivingHoursCommandTest extends TestCase
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

    public function test_fails_when_a_place_is_missing_coordinates(): void
    {
        // Default state: no city has lat/lng yet.
        $this->artisan('namibway:backfill-place-driving-hours')->assertFailed();

        $this->assertSame(0, DB::table('place_driving_hours')->count());
    }

    public function test_dry_run_computes_but_writes_nothing(): void
    {
        Place::query()->update(['lat' => -22.0, 'lng' => 17.0]);
        $this->fakeOsrmTable(7200);

        $this->artisan('namibway:backfill-place-driving-hours', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('place_driving_hours')->count());
    }

    public function test_one_place_without_coordinates_stops_the_whole_run(): void
    {
        Place::query()->update(['lat' => -22.0, 'lng' => 17.0]);

        $sesriem = Place::where('slug', 'sesriem')->first() ?? Place::query()->firstOrFail();
        $sesriem->update(['lat' => null, 'lng' => null]);

        $this->artisan('namibway:backfill-place-driving-hours')->assertFailed();
        $this->assertSame(0, DB::table('place_driving_hours')->count());

        $sesriem->update(['lat' => -24.4833, 'lng' => 15.8]);
        $this->fakeOsrmTable(7200);

        $this->artisan('namibway:backfill-place-driving-hours')->assertSuccessful();

        $this->assertTrue(
            DB::table('place_driving_hours')
                ->where('place_a_id', $sesriem->id)
                ->orWhere('place_b_id', $sesriem->id)
                ->exists(),
        );
    }

    public function test_computes_and_upserts_real_pairs(): void
    {
        $inScope = Place::query();
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

        $this->artisan('namibway:backfill-place-driving-hours')->assertSuccessful();

        $this->assertSame($expectedPairs, DB::table('place_driving_hours')->count());

        $windhoek = Place::where('slug', 'windhoek')->firstOrFail();
        $swakopmund = Place::where('slug', 'swakopmund')->firstOrFail();

        $row = DB::table('place_driving_hours')
            ->where('place_a_id', min($windhoek->id, $swakopmund->id))
            ->where('place_b_id', max($windhoek->id, $swakopmund->id))
            ->first();

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(2.0, $row->hours, 0.001);

        // Rerun with a different duration — upsert should update in place, not duplicate.
        $seconds = 3600;
        $this->artisan('namibway:backfill-place-driving-hours')->assertSuccessful();

        $this->assertSame($expectedPairs, DB::table('place_driving_hours')->count());

        $updated = DB::table('place_driving_hours')
            ->where('place_a_id', min($windhoek->id, $swakopmund->id))
            ->where('place_b_id', max($windhoek->id, $swakopmund->id))
            ->first();

        $this->assertEqualsWithDelta(1.0, $updated->hours, 0.001);
    }

    public function test_the_refusal_names_the_places_and_the_right_command(): void
    {
        Place::query()->update(['lat' => -22.0, 'lng' => 17.0]);

        $erindi = Place::where('slug', 'erindi-private-game-reserve')->firstOrFail();
        $erindi->update(['lat' => null, 'lng' => null]);

        // Until 2026-08-23 this pointed at the *city* geocoder, which cannot
        // help a park, and it never said which row was the problem.
        $this->artisan('namibway:backfill-place-driving-hours')
            ->expectsOutputToContain('Erindi Private Game Reserve')
            ->expectsOutputToContain('namibway:backfill-place-coordinates')
            ->assertFailed();
    }
}
