<?php

namespace Tests\Feature\Content;

use App\Enums\ListingType;
use App\Models\City;
use App\Models\Destination;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A place answers two different questions, and only one of them is a
 * traveler's. "Onguma Nature Reserve (Oshikoto)" names the administration the
 * reserve pays its levies to; "Onguma Nature Reserve (Etosha)" names the trip.
 * So a place carries both, and everything a traveler reads prefers the area.
 */
class TourismAreasTest extends TestCase
{
    use RefreshDatabase;

    private function onguma(): City
    {
        return City::where('slug', 'onguma-nature-reserve')->firstOrFail();
    }

    private function lodgeOnOnguma(): Listing
    {
        return Listing::factory()->create([
            'type' => ListingType::Accommodation,
            'name' => 'Onguma Tented Camp',
            'city_id' => $this->onguma()->id,
            'is_published' => true,
        ]);
    }

    public function test_the_migration_files_the_reserves_around_a_park_under_it(): void
    {
        $etosha = Destination::where('slug', 'etosha')->firstOrFail();

        $this->assertSame('Etosha', $this->onguma()->destination?->getTranslation('name', 'en'));
        $this->assertEqualsCanonicalizing(
            ['Etosha National Park', 'Onguma Nature Reserve', 'Ongava Game Reserve'],
            $etosha->cities->pluck('name')->all(),
        );

        // The political region is untouched and still says what it always said.
        $this->assertSame('Oshikoto', $this->onguma()->region->name);
    }

    public function test_a_place_may_belong_to_no_area(): void
    {
        // Most of the gazetteer is exactly this — a town that stands for
        // itself. The traveler is then shown the place alone.
        $this->assertNull(City::where('slug', 'otjiwarongo')->firstOrFail()->destination);
    }

    public function test_a_listing_reports_the_area_of_its_place(): void
    {
        $listing = $this->lodgeOnOnguma();

        $this->assertSame('Etosha', $listing->area);
        $this->assertSame('Oshikoto', $listing->region);
    }

    public function test_the_area_filter_finds_what_the_political_region_would_miss(): void
    {
        $listing = $this->lodgeOnOnguma();

        // This is the destination card's click: "Etosha" as a place to go.
        // Filtering on the political region instead would return nothing here
        // and the Skeleton Coast everywhere else.
        $found = Listing::query()->filterBy(['region' => 'Etosha'])->pluck('id');

        $this->assertContains($listing->id, $found->all());
    }

    public function test_the_homepage_hands_the_area_to_the_frontend(): void
    {
        $this->lodgeOnOnguma();

        // The payload is the point, not the page shell — and without this the
        // assertion needs a built Vite manifest to say anything at all.
        $this->withoutVite();

        // The only published listing in the catalog is the homepage's cover
        // story, so that is where it lands — see HomeController::pickFeatured.
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('featuredPick.name', 'Onguma Tented Camp')
                ->where('featuredPick.area', 'Etosha')
                ->where('featuredPick.region', 'Oshikoto')
                ->etc());
    }
}
