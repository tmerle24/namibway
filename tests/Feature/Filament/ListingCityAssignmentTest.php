<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ListingResource\Pages\ListListings;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Imported listings arrive with no city_id (ImportProviders leaves it for a
 * human — the scrape's free-text region is no reliable match for a City), and
 * BackfillListingCities can only fill it in from an address, which the NTB
 * source doesn't carry. The "Has city" filter plus the "Assign city" bulk
 * action are the manual route for those rows, so both are pinned here — most
 * importantly the bulk action's fill-only guard.
 */
class ListingCityAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_the_has_city_filter_separates_listings_with_and_without_one(): void
    {
        // The 14 regions and ~100 cities come from the create_regions_table /
        // create_cities_table migrations, so these exist without a factory.
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();

        $placed = Listing::factory()->create(['name' => 'Placed Rentals', 'city_id' => $windhoek->id]);
        $orphan = Listing::factory()->create(['name' => 'Orphan Rentals', 'city_id' => null]);

        Livewire::actingAs($this->admin())
            ->test(ListListings::class)
            ->filterTable('has_city', false)
            ->assertCanSeeTableRecords([$orphan])
            ->assertCanNotSeeTableRecords([$placed])
            ->filterTable('has_city', true)
            ->assertCanSeeTableRecords([$placed])
            ->assertCanNotSeeTableRecords([$orphan]);
    }

    public function test_assign_city_fills_empty_listings_and_leaves_assigned_ones_alone(): void
    {
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();
        $swakopmund = City::where('slug', 'swakopmund')->firstOrFail();

        $orphan = Listing::factory()->create(['city_id' => null]);
        // Already placed, and deliberately somewhere else — a bulk write must
        // not overwrite it (see CLAUDE.md's backfill-listing-cities lesson).
        $placed = Listing::factory()->create(['city_id' => $swakopmund->id]);

        Livewire::actingAs($this->admin())
            ->test(ListListings::class)
            ->callTableBulkAction('assign_city', [$orphan, $placed], data: [
                'city_id' => $windhoek->id,
            ]);

        $this->assertSame($windhoek->id, $orphan->fresh()->city_id);
        $this->assertSame($swakopmund->id, $placed->fresh()->city_id);
    }
}
