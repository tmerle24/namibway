<?php

namespace Tests\Feature;

use App\Enums\ListingType;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportProvidersCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $emptyYp;

    protected function setUp(): void
    {
        parent::setUp();

        // The default --yp path points at real scraped data on disk; override it
        // with an empty fixture so these tests stay hermetic and fast.
        $this->emptyYp = $this->writeFixture([]);
    }

    public function test_it_classifies_vehicle_businesses_by_name_when_source_type_is_missing(): void
    {
        $path = $this->writeFixture([
            ['name' => 'You Drive Car Hire Namibia'],
            ['name' => 'CM Car Hire and Vehicle Lease'],
        ]);

        $this->artisan('providers:import', ['--ntb' => $path, '--yp' => $this->emptyYp])->assertSuccessful();

        $this->assertSame(ListingType::Vehicle, Listing::where('slug', 'you-drive-car-hire-namibia')->value('type'));
        $this->assertSame(ListingType::Vehicle, Listing::where('slug', 'cm-car-hire-and-vehicle-lease')->value('type'));
    }

    public function test_it_classifies_activity_businesses_by_name(): void
    {
        $path = $this->writeFixture([
            ['name' => 'Namibia Tours and Safaris'],
        ]);

        $this->artisan('providers:import', ['--ntb' => $path, '--yp' => $this->emptyYp])->assertSuccessful();

        $this->assertSame(ListingType::Activity, Listing::where('slug', 'namibia-tours-and-safaris')->value('type'));
    }

    public function test_accommodation_keywords_take_precedence_over_activity_or_vehicle_keywords(): void
    {
        $path = $this->writeFixture([
            ['name' => 'Etosha Safari Lodge'],
        ]);

        $this->artisan('providers:import', ['--ntb' => $path, '--yp' => $this->emptyYp])->assertSuccessful();

        $this->assertSame(ListingType::Accommodation, Listing::where('slug', 'etosha-safari-lodge')->value('type'));
    }

    public function test_it_respects_an_explicit_clean_type_field(): void
    {
        $path = $this->writeFixture([
            ['name' => 'Windhoek Guest House', 'type' => 'accommodation'],
            ['name' => 'Joe\'s Beerhouse', 'type' => 'restaurant'],
        ]);

        $this->artisan('providers:import', ['--ntb' => $path, '--yp' => $this->emptyYp])->assertSuccessful();

        $this->assertSame(ListingType::Accommodation, Listing::where('slug', 'windhoek-guest-house')->value('type'));
        $this->assertSame(ListingType::Restaurant, Listing::where('slug', 'joes-beerhouse')->value('type'));
    }

    public function test_non_travel_businesses_fall_back_to_accommodation_but_stay_unpublished(): void
    {
        $path = $this->writeFixture([
            ['name' => 'Tycoon Engineering & Marine Services'],
        ]);

        $this->artisan('providers:import', ['--ntb' => $path, '--yp' => $this->emptyYp])->assertSuccessful();

        $listing = Listing::where('slug', 'tycoon-engineering-marine-services')->first();
        $this->assertNotNull($listing);
        $this->assertSame(ListingType::Accommodation, $listing->type);
        $this->assertFalse((bool) $listing->is_published);
    }

    /** @param array<int, array<string, mixed>> $records */
    private function writeFixture(array $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ntb').'.json';
        file_put_contents($path, json_encode($records));

        $this->beforeApplicationDestroyed(function () use ($path) {
            @unlink($path);
        });

        return $path;
    }
}
