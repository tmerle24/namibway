<?php

namespace Tests\Feature\Content;

use App\Enums\PlaceType;
use App\Models\City;
use App\Models\Listing;
use App\Models\Place;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A lodge that stands in a park, on a private reserve or at a landmark must
 * have somewhere to be filed. Until 2026-08-18 the gazetteer held proclaimed
 * settlements only, so Onguma and the Etosha camps had to borrow the nearest
 * town — ~100 km from where they are, and that town is what the trip plan then
 * showed and measured driving hours from.
 *
 * The first answer put those rows in `cities`, which broke the noun the other
 * way: a table of addresses held Etosha National Park. Since 2026-08-23 they
 * are `places` — where a traveler goes, as distinct from where post arrives.
 */
class TourismPlacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tourism_places_are_seeded_and_are_not_cities(): void
    {
        $etosha = Place::where('slug', 'etosha-national-park')->first();
        $onguma = Place::where('slug', 'onguma-nature-reserve')->first();

        $this->assertNotNull($etosha, 'Etosha has to be selectable as a place.');
        $this->assertNotNull($onguma, 'Onguma has to be selectable as a place.');

        $this->assertSame(PlaceType::NationalPark, $etosha->type);
        $this->assertSame(PlaceType::NatureReserve, $onguma->type);
        $this->assertSame('Kunene', $etosha->region->name);
        $this->assertSame('Oshikoto', $onguma->region->name);

        // The whole point of the split: a park is not an address.
        $this->assertNull(City::where('slug', 'etosha-national-park')->first());
        $this->assertNull(City::where('slug', 'onguma-nature-reserve')->first());
    }

    public function test_a_town_a_traveler_plans_around_is_a_place_as_well_as_a_city(): void
    {
        $city = City::where('slug', 'windhoek')->firstOrFail();
        $place = Place::where('slug', 'windhoek')->firstOrFail();

        // One dot on the map seen twice — as an address, and as a stop on a
        // trip. cities.place_id is what says they are the same dot.
        $this->assertSame($place->id, $city->place_id);
        $this->assertSame(PlaceType::Town, $place->type);
        $this->assertSame($city->id, $place->city_id);
    }

    public function test_a_village_nobody_stays_in_gets_no_place(): void
    {
        $dordabis = City::where('slug', 'dordabis')->firstOrFail();

        $this->assertNull($dordabis->place_id);
    }

    public function test_a_listing_takes_its_city_place_but_an_explicit_one_wins(): void
    {
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();
        $etosha = Place::where('slug', 'etosha-national-park')->firstOrFail();

        $listing = Listing::factory()->create(['city_id' => $windhoek->id]);
        $this->assertSame($windhoek->place_id, $listing->fresh()->place_id);

        // A camp posted to a town but standing in a park: the address is real
        // and so is the place, and the place must not be overwritten by it.
        $onguma = Listing::factory()->create(['city_id' => $windhoek->id, 'place_id' => $etosha->id]);
        $onguma->update(['name' => 'Onguma Bush Camp']);

        $this->assertSame($etosha->id, $onguma->fresh()->place_id);
    }

    public function test_seeding_the_places_twice_changes_nothing(): void
    {
        $before = City::count();

        $this->seed(CitySeeder::class);

        $this->assertSame($before, City::count());
    }
}
