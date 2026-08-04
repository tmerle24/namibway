<?php

namespace Tests\Feature\Commands;

use App\Models\City;
use App\Models\Listing;
use App\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a real production incident (2026-08-04): a
 * "Windhoek" match in a listing's free-text address overwrote its existing,
 * more specific city_id — many remote/rural operators give a postal address
 * routed through Windhoek (the capital) regardless of physical location,
 * e.g. a real listing near Etosha's Andersson Gate whose stored address
 * literally ends "...C38, Windhoek, Namibia". The command must never let a
 * Windhoek match override an existing different city_id.
 */
class BackfillListingCitiesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function listing(array $overrides): Listing
    {
        $partner = Partner::create(['name' => 'Test Partner '.uniqid()]);

        return Listing::factory()->create(array_merge([
            'partner_id' => $partner->id,
        ], $overrides));
    }

    public function test_a_windhoek_match_does_not_override_an_existing_different_city(): void
    {
        $outjo = City::where('slug', 'outjo')->firstOrFail();

        $listing = $this->listing([
            'name' => 'Etosha Safari Campsites',
            'city_id' => $outjo->id,
            'address' => '10 km South of Andersson Gate on C38, Windhoek, Namibia',
        ]);

        $this->artisan('namibway:backfill-listing-cities')->assertSuccessful();

        $this->assertSame($outjo->id, $listing->fresh()->city_id);
    }

    public function test_a_windhoek_match_still_fills_in_a_previously_unset_city(): void
    {
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();

        $listing = $this->listing([
            'name' => 'Some Windhoek Guesthouse',
            'city_id' => null,
            'address' => '5 Independence Ave, Windhoek, Namibia',
        ]);

        $this->artisan('namibway:backfill-listing-cities')->assertSuccessful();

        $this->assertSame($windhoek->id, $listing->fresh()->city_id);
    }

    public function test_a_non_windhoek_match_still_updates_an_existing_different_city(): void
    {
        $outjo = City::where('slug', 'outjo')->firstOrFail();
        $swakopmund = City::where('slug', 'swakopmund')->firstOrFail();

        $listing = $this->listing([
            'name' => 'Jetty 1905',
            'city_id' => $outjo->id,
            'address' => 'The Jetty, Swakopmund, Namibia',
        ]);

        $this->artisan('namibway:backfill-listing-cities')->assertSuccessful();

        $this->assertSame($swakopmund->id, $listing->fresh()->city_id);
    }
}
