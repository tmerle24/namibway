<?php

namespace Tests\Feature\Commands;

use App\Models\City;
use App\Models\Listing;
use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The last mile of the city/place split: a camp on Onguma was still filed at
 * Outjo, so the trip plan printed a stage called "Outjo (Kunene)" — which tells
 * a traveller nothing and is 100 km from where they will be.
 */
class BackfillListingPlacesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function onguma(): Place
    {
        return Place::where('slug', 'onguma-nature-reserve')->firstOrFail();
    }

    private function campAtOutjo(string $name): Listing
    {
        $outjo = City::where('slug', 'outjo')->firstOrFail();

        return Listing::factory()->create([
            'name' => $name,
            'city_id' => $outjo->id,
            'is_published' => true,
        ]);
    }

    public function test_a_name_wins_over_a_postal_address(): void
    {
        $camp = $this->campAtOutjo('Onguma Bush Camp');

        $this->assertSame('Outjo', $camp->fresh()->place?->name, 'Precondition: filed at the town.');

        $this->artisan('namibway:backfill-listing-places', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Onguma Nature Reserve', $camp->fresh()->place?->name);

        // The address is untouched — post still goes to Outjo.
        $this->assertSame('Outjo', $camp->fresh()->city?->name);
    }

    public function test_a_dry_run_is_the_default_and_writes_nothing(): void
    {
        $camp = $this->campAtOutjo('Onguma Bush Camp');

        $this->artisan('namibway:backfill-listing-places')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame('Outjo', $camp->fresh()->place?->name);
    }

    public function test_coordinates_move_a_listing_that_sits_on_top_of_a_place(): void
    {
        $onguma = $this->onguma();
        $onguma->update(['lat' => -18.65, 'lng' => 17.05]);

        // No "Onguma" in the name, so only the coordinates can say.
        $camp = $this->campAtOutjo('Tamboti Luxury Tented Camp');
        $camp->update(['latitude' => -18.66, 'longitude' => 17.06]);

        $this->artisan('namibway:backfill-listing-places', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Onguma Nature Reserve', $camp->fresh()->place?->name);
    }

    public function test_coordinates_far_away_move_nothing(): void
    {
        $this->onguma()->update(['lat' => -18.65, 'lng' => 17.05]);

        $far = $this->campAtOutjo('Somewhere Else Lodge');
        $far->update(['latitude' => -22.55, 'longitude' => 17.08]);

        $this->artisan('namibway:backfill-listing-places', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Outjo', $far->fresh()->place?->name);
    }

    public function test_a_place_already_set_is_never_second_guessed(): void
    {
        $etosha = Place::where('slug', 'etosha-national-park')->firstOrFail();

        // Named for one place, filed at another by a person who knew better.
        $camp = $this->campAtOutjo('Onguma Bush Camp');
        $camp->place()->associate($etosha);
        $camp->saveQuietly();

        $this->artisan('namibway:backfill-listing-places', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Etosha National Park', $camp->fresh()->place?->name);
    }

    public function test_a_name_that_fights_its_coordinates_is_left_for_a_human(): void
    {
        $this->onguma()->update(['lat' => -18.65, 'lng' => 17.05]);

        // Called Onguma, pinned in the far south. One of the two is wrong and
        // the command is not the thing that should decide which.
        $camp = $this->campAtOutjo('Onguma Bush Camp');
        $camp->update(['latitude' => -27.5, 'longitude' => 17.6]);

        $this->artisan('namibway:backfill-listing-places', ['--apply' => true])
            ->expectsOutputToContain('Needs a human')
            ->assertSuccessful();

        $this->assertSame('Outjo', $camp->fresh()->place?->name);
    }
}
