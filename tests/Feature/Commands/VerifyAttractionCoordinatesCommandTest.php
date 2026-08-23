<?php

namespace Tests\Feature\Commands;

use App\Models\Attraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The seeded corpus was written from knowledge rather than read off a map, and
 * this is what turns 47 rows nobody would check by hand into a short list of
 * ones worth looking at.
 */
class VerifyAttractionCoordinatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOsm(float $lat, float $lng): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => (string) $lat, 'lon' => (string) $lng, 'display_name' => 'Test Result, Namibia'],
            ], 200),
        ]);
    }

    public function test_a_pin_that_agrees_is_not_reported(): void
    {
        Attraction::query()->whereKeyNot(
            Attraction::where('slug', 'hoba-meteorite')->value('id')
        )->delete();

        // ~200 m away, well inside the default 3 km threshold.
        $this->fakeOsm(-19.5943, 17.9333);

        $this->artisan('namibway:verify-attraction-coordinates')
            ->expectsOutputToContain('Checking 1 attractions')
            ->doesntExpectOutputToContain('disagree by more than')
            ->assertSuccessful();
    }

    public function test_a_pin_that_disagrees_is_reported_but_not_changed(): void
    {
        $hoba = Attraction::where('slug', 'hoba-meteorite')->firstOrFail();
        Attraction::query()->whereKeyNot($hoba->id)->delete();

        // Windhoek — several hundred kilometres away.
        $this->fakeOsm(-22.5597, 17.0832);

        $this->artisan('namibway:verify-attraction-coordinates')
            ->expectsOutputToContain('disagree by more than')
            ->assertSuccessful();

        // Report only: OSM is often worse than we are for a farm gate, so a
        // disagreement means "somebody look", never "OSM wins".
        $this->assertEqualsWithDelta(-19.5925, $hoba->fresh()->lat, 0.0001);
    }

    public function test_apply_overwrites_only_what_is_over_the_threshold(): void
    {
        $hoba = Attraction::where('slug', 'hoba-meteorite')->firstOrFail();
        Attraction::query()->whereKeyNot($hoba->id)->delete();

        $this->fakeOsm(-22.5597, 17.0832);

        $this->artisan('namibway:verify-attraction-coordinates', ['--apply' => true])
            ->assertSuccessful();

        $this->assertEqualsWithDelta(-22.5597, $hoba->fresh()->lat, 0.0001);
        $this->assertEqualsWithDelta(17.0832, $hoba->fresh()->lng, 0.0001);
    }

    public function test_no_answer_from_osm_leaves_our_value_alone(): void
    {
        $hoba = Attraction::where('slug', 'hoba-meteorite')->firstOrFail();
        Attraction::query()->whereKeyNot($hoba->id)->delete();

        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([], 200)]);

        $this->artisan('namibway:verify-attraction-coordinates', ['--apply' => true])
            ->expectsOutputToContain('had no answer')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(-19.5925, $hoba->fresh()->lat, 0.0001);
    }
}
