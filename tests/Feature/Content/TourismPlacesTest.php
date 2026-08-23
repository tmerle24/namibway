<?php

namespace Tests\Feature\Content;

use App\Enums\CityType;
use App\Models\City;
use App\Models\Listing;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A lodge that stands in a park, on a private reserve or at a landmark must
 * have somewhere to be filed. Before 2026-08-18 the gazetteer held proclaimed
 * settlements only, so Onguma and the Etosha camps had to borrow the nearest
 * town — ~100 km from where they are, and that town is what the trip plan then
 * showed and measured driving hours from.
 */
class TourismPlacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_places_that_are_not_towns_are_seeded_by_the_migration(): void
    {
        $etosha = City::where('slug', 'etosha-national-park')->first();
        $onguma = City::where('slug', 'onguma-nature-reserve')->first();

        $this->assertNotNull($etosha, 'Etosha has to be selectable as a place.');
        $this->assertNotNull($onguma, 'Onguma has to be selectable as a place.');

        $this->assertSame(CityType::NationalPark, $etosha->type);
        $this->assertSame(CityType::NatureReserve, $onguma->type);
        $this->assertSame('Kunene', $etosha->region->name);
        $this->assertSame('Oshikoto', $onguma->region->name);

        // No lat/lng here on purpose — namibway:backfill-city-coordinates
        // geocodes them, exactly as it did for every settlement.
        $this->assertNull($etosha->lat);
        $this->assertNull($etosha->lng);
    }

    public function test_a_listing_can_be_filed_in_a_park_rather_than_the_nearest_town(): void
    {
        $etosha = City::where('slug', 'etosha-national-park')->firstOrFail();

        $listing = Listing::factory()->create(['city_id' => $etosha->id]);

        $this->assertSame('Etosha National Park', $listing->fresh()->city?->name);
        $this->assertTrue($etosha->listings()->whereKey($listing->id)->exists());
    }

    public function test_a_tourism_area_is_routed_between_like_any_other_place(): void
    {
        // Whether Kaia's daily-drive check can measure a leg at all depends on
        // the place having a row in the city-to-city matrix. A park is where
        // the beds are, so it is in scope; a 200-soul settlement is not.
        $this->assertContains(CityType::NationalPark->value, CityType::drivingMatrixValues());
        $this->assertContains(CityType::NatureReserve->value, CityType::drivingMatrixValues());
        $this->assertContains(CityType::Landmark->value, CityType::drivingMatrixValues());
        $this->assertNotContains(CityType::Settlement->value, CityType::drivingMatrixValues());
    }

    public function test_a_tourism_area_is_not_a_settlement(): void
    {
        $this->assertFalse(CityType::NationalPark->isSettlement());
        $this->assertFalse(CityType::NatureReserve->isSettlement());
        $this->assertFalse(CityType::Landmark->isSettlement());
        $this->assertTrue(CityType::Town->isSettlement());
    }

    public function test_seeding_the_places_twice_changes_nothing(): void
    {
        $before = City::count();

        $this->seed(CitySeeder::class);

        $this->assertSame($before, City::count());
    }
}
