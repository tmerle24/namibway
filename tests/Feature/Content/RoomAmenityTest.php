<?php

namespace Tests\Feature\Content;

use App\Enums\AmenityCategory;
use App\Enums\AmenityScope;
use App\Models\Amenity;
use App\Models\BookableUnit;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared amenity catalogue and what a room claims from it.
 *
 * The catalogue ships in the migration, so the first thing worth asserting is
 * that it is actually there — a picker with nothing in it is how a feature
 * quietly does not exist.
 */
class RoomAmenityTest extends TestCase
{
    use RefreshDatabase;

    private function room(): BookableUnit
    {
        return BookableUnit::factory()->create(['listing_id' => Listing::factory()]);
    }

    public function test_the_catalogue_ships_with_the_migration(): void
    {
        $this->assertGreaterThan(30, Amenity::count());

        // The Namibian ones, which are the reason this list was not copied
        // from a generic hotel catalogue.
        foreach (['outdoor_shower', 'mosquito_nets', 'generator_hours', 'no_power_at_night', 'braai'] as $code) {
            $this->assertTrue(
                Amenity::where('code', $code)->exists(),
                "The catalogue is missing [{$code}].",
            );
        }
    }

    public function test_every_amenity_has_a_category_the_enum_knows(): void
    {
        foreach (Amenity::all() as $amenity) {
            $this->assertInstanceOf(AmenityCategory::class, $amenity->category);
        }
    }

    public function test_a_room_claims_amenities_and_reads_them_back_grouped(): void
    {
        $room = $this->room();

        $room->amenities()->sync(Amenity::whereIn('code', [
            'no_power_at_night',
            'outdoor_shower',
            'ensuite_bathroom',
            'king_bed',
        ])->pluck('id'));

        $grouped = $room->load('amenities')->amenitiesByCategory();

        // Beds first, then bathroom, then power — the order a room
        // description reads in, not alphabetical.
        $this->assertSame(['Beds', 'Bathroom', 'Power and connectivity'], array_keys($grouped));
        $this->assertSame(['King bed'], $grouped['Beds']);
        $this->assertContains('Outdoor shower', $grouped['Bathroom']);
        $this->assertContains('En-suite bathroom', $grouped['Bathroom']);

        // A category the room says nothing about is left out rather than
        // shown empty.
        $this->assertArrayNotHasKey('Services', $grouped);
    }

    public function test_retiring_an_amenity_leaves_the_rooms_that_have_it_alone(): void
    {
        $room = $this->room();
        $shower = Amenity::where('code', 'outdoor_shower')->firstOrFail();

        $room->amenities()->attach($shower);
        $shower->update(['is_active' => false]);

        // Gone from the picker …
        $this->assertFalse(Amenity::catalogue()->contains('id', $shower->id));

        // … and still true of the room, which is the point of retiring rather
        // than deleting.
        $this->assertTrue($room->load('amenities')->amenities->contains('id', $shower->id));
    }

    public function test_the_catalogue_reads_in_category_order(): void
    {
        $categories = Amenity::catalogue()
            ->map(fn (Amenity $amenity) => $amenity->category->sort())
            ->all();

        $sorted = $categories;
        sort($sorted);

        $this->assertSame($sorted, $categories, 'The catalogue is not grouped by category.');
    }

    public function test_a_property_that_has_chosen_amenities_ignores_the_scraped_text(): void
    {
        $listing = Listing::factory()->create([
            'facilities' => ['pool', 'restaurant', 'wifi'],
        ]);

        // Nothing chosen yet: the scraped guess is all there is, so it is what
        // gets shown.
        $this->assertSame(['pool', 'restaurant', 'wifi'], $listing->amenityList());
        $this->assertFalse($listing->hasChosenAmenities());

        $listing->amenities()->attach(Amenity::whereIn('code', ['swimming_pool', 'restaurant'])->pluck('id'));

        $listing = $listing->fresh()?->load('amenities');

        // Once somebody who owns the place has said, the free text stops being
        // an answer — not merged with it, which would put "pool" beside
        // "Swimming pool" and make the owner's own list look careless.
        $this->assertSame(['Swimming pool', 'Restaurant'], $listing?->amenityList());
        $this->assertTrue($listing?->hasChosenAmenities());

        // And it is ignored, not deleted: the record of what the source
        // claimed survives.
        $this->assertSame(['pool', 'restaurant', 'wifi'], $listing?->facilities);
    }

    public function test_the_backfill_maps_the_scrapers_own_keys_and_reports_the_rest(): void
    {
        $listing = Listing::factory()->create([
            'facilities' => ['pool', 'restaurant', 'airstrip', 'llama trekking'],
        ]);

        $this->artisan('amenities:backfill-listings', ['--dry-run' => true])
            ->expectsOutputToContain('Would attach 3')
            ->expectsOutputToContain('llama trekking')
            ->assertSuccessful();

        // A dry run wrote nothing.
        $this->assertSame(0, $listing->amenities()->count());

        $this->artisan('amenities:backfill-listings')->assertSuccessful();

        $codes = $listing->load('amenities')->amenities->pluck('code')->sort()->values()->all();

        $this->assertSame(['airstrip', 'restaurant', 'swimming_pool'], $codes);

        // The one it could not place is still in the free text, which is where
        // somebody can see it and decide whether it deserves a catalogue entry.
        $this->assertContains('llama trekking', $listing->facilities ?? []);
    }

    public function test_the_backfill_never_touches_a_property_that_has_already_chosen(): void
    {
        $listing = Listing::factory()->create(['facilities' => ['pool', 'restaurant']]);
        $listing->amenities()->attach(Amenity::where('code', 'bar')->firstOrFail());

        $this->artisan('amenities:backfill-listings')->assertSuccessful();

        // A scraper's guess does not get to add to what an owner chose, let
        // alone correct it.
        $this->assertSame(['bar'], $listing->load('amenities')->amenities->pluck('code')->all());
    }

    public function test_the_catalogue_can_be_narrowed_to_where_it_applies(): void
    {
        $rooms = Amenity::catalogue(AmenityScope::Room)->pluck('code');
        $properties = Amenity::catalogue(AmenityScope::Property)->pluck('code');

        $this->assertContains('mosquito_nets', $rooms);
        $this->assertNotContains('mosquito_nets', $properties);

        $this->assertContains('swimming_pool', $properties);
        $this->assertNotContains('swimming_pool', $rooms);

        // Asked about at both levels, and often with different answers.
        $this->assertContains('wifi', $rooms);
        $this->assertContains('wifi', $properties);
    }

    public function test_a_room_cannot_claim_the_same_amenity_twice(): void
    {
        $room = $this->room();
        $wifi = Amenity::where('code', 'wifi')->firstOrFail();

        $room->amenities()->sync([$wifi->id, $wifi->id]);
        $room->amenities()->syncWithoutDetaching([$wifi->id]);

        $this->assertSame(1, $room->load('amenities')->amenities->count());
    }
}
