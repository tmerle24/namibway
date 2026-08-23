<?php

namespace Tests\Feature\Filament;

use App\Models\City;
use App\Models\Place;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeographyResourcesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_regions_list_and_edit_pages_render(): void
    {
        // The 14 official regions are seeded by the create_regions_table
        // migration itself (see its docblock), so this exists without a factory.
        $region = Region::where('name', 'Khomas')->firstOrFail();

        $this->actingAs($this->admin())
            ->get('/admin/regions')
            ->assertOk()
            ->assertSee('Khomas');

        $this->actingAs($this->admin())
            ->get("/admin/regions/{$region->id}/edit")
            ->assertOk();
    }

    public function test_cities_list_and_edit_pages_render_with_their_region(): void
    {
        // Aranos sorts near the top alphabetically, so it lands on the
        // table's default first page among the ~100 seeded cities —
        // unlike e.g. Windhoek, which the default name-sort pushes past it.
        $city = City::where('slug', 'aranos')->firstOrFail();

        $this->actingAs($this->admin())
            ->get('/admin/cities')
            ->assertOk()
            ->assertSee('Aranos')
            ->assertSee('Hardap');

        $this->actingAs($this->admin())
            ->get("/admin/cities/{$city->id}/edit")
            ->assertOk();
    }

    public function test_city_belongs_to_its_region(): void
    {
        $city = City::where('slug', 'swakopmund')->firstOrFail();

        $this->assertSame('Erongo', $city->region->name);
    }

    public function test_places_list_and_edit_pages_render(): void
    {
        $etosha = Place::where('slug', 'etosha-national-park')->firstOrFail();

        $this->actingAs($this->admin())
            ->get('/admin/places')
            ->assertOk()
            ->assertSee('Etosha National Park');

        $this->actingAs($this->admin())
            ->get("/admin/places/{$etosha->id}/edit")
            ->assertOk();
    }

    public function test_cities_and_places_are_two_screens_holding_two_things(): void
    {
        // The whole point of the 2026-08-23 split. A park is not an address
        // and must not turn up on the screen of addresses; a town is both, so
        // it is on both, linked by cities.place_id.
        $this->assertNull(City::where('slug', 'etosha-national-park')->first());
        $this->assertNotNull(Place::where('slug', 'etosha-national-park')->first());

        $windhoek = City::where('slug', 'windhoek')->firstOrFail();

        $this->assertNotNull($windhoek->place);
        $this->assertSame('Windhoek', $windhoek->place->name);
    }
}
