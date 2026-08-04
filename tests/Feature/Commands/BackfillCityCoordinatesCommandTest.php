<?php

namespace Tests\Feature\Commands;

use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every City row starts with lat/lng = NULL (see create_cities_table.php —
 * seeded directly there with no coordinates), so a freshly migrated
 * RefreshDatabase test DB is exactly the "nothing geocoded yet" state this
 * command targets, no extra setup needed.
 */
class BackfillCityCoordinatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fakeNominatim(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '-22.5597', 'lon' => '17.0832', 'display_name' => 'Test Result, Namibia'],
            ], 200),
        ]);
    }

    public function test_dry_run_leaves_the_database_untouched(): void
    {
        $this->fakeNominatim();

        $this->artisan('namibway:backfill-city-coordinates', ['--dry-run' => true, '--limit' => 3])
            ->assertSuccessful();

        $this->assertSame(0, City::whereNotNull('lat')->count());
    }

    public function test_geocodes_missing_cities_up_to_the_limit(): void
    {
        $this->fakeNominatim();

        $this->artisan('namibway:backfill-city-coordinates', ['--limit' => 3])
            ->assertSuccessful();

        $this->assertSame(3, City::whereNotNull('lat')->whereNotNull('lng')->count());

        $geocoded = City::whereNotNull('lat')->first();
        $this->assertEqualsWithDelta(-22.5597, $geocoded->lat, 0.0001);
        $this->assertEqualsWithDelta(17.0832, $geocoded->lng, 0.0001);

        Http::assertSentCount(3);
    }

    public function test_already_filled_cities_are_skipped_without_an_http_call(): void
    {
        // Fill every city except two, so the command has only 2 real rows left
        // to process — keeps this fast (OsmLocationFinder's real 1req/s throttle
        // still applies even against faked HTTP, so a full 105-city run here
        // would be needlessly slow).
        City::query()->update(['lat' => -22.5597, 'lng' => 17.0832]);
        City::query()->inRandomOrder()->limit(2)->pluck('id')
            ->each(fn (int $id) => City::where('id', $id)->update(['lat' => null, 'lng' => null]));

        $this->fakeNominatim();

        $this->artisan('namibway:backfill-city-coordinates')->assertSuccessful();

        Http::assertSentCount(2);
    }
}
