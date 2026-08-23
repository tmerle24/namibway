<?php

namespace Tests\Feature\Commands;

use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A gap the city/place split opened: parks used to be rows in `cities`, so the
 * city geocoder covered them. They are places now and nothing did, which keeps
 * them out of the driving matrix and off the map.
 */
class BackfillPlaceCoordinatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_geocodes_places_that_have_none(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '-19.1817', 'lon' => '15.9200', 'display_name' => 'Etosha, Namibia'],
            ], 200),
        ]);

        $etosha = Place::where('slug', 'etosha-national-park')->firstOrFail();
        $this->assertNull($etosha->lat);

        // Nominatim is throttled to one request a second, so the fixture is cut
        // down rather than the whole gazetteer geocoded for one assertion.
        Place::query()->whereKeyNot($etosha->id)->delete();

        $this->artisan('namibway:backfill-place-coordinates')->assertSuccessful();

        $this->assertNotNull($etosha->fresh()->lat);
    }

    public function test_a_coordinate_set_by_hand_is_not_second_guessed(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '-22.5597', 'lon' => '17.0832', 'display_name' => 'Windhoek, Namibia'],
            ], 200),
        ]);

        // The centre of a park is a judgement call, not a lookup.
        $etosha = Place::where('slug', 'etosha-national-park')->firstOrFail();
        $etosha->update(['lat' => -19.0, 'lng' => 16.0]);
        Place::query()->whereKeyNot($etosha->id)->delete();

        $this->artisan('namibway:backfill-place-coordinates')->assertSuccessful();

        $this->assertEqualsWithDelta(-19.0, $etosha->fresh()->lat, 0.0001);
    }

    public function test_dry_run_writes_nothing(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '-19.1817', 'lon' => '15.9200', 'display_name' => 'Etosha, Namibia'],
            ], 200),
        ]);

        Place::query()->whereKeyNot(Place::where('slug', 'etosha-national-park')->value('id'))->delete();

        $this->artisan('namibway:backfill-place-coordinates', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull(Place::where('slug', 'etosha-national-park')->firstOrFail()->lat);
    }
}
