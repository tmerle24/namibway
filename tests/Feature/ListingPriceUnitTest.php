<?php

namespace Tests\Feature;

use App\Enums\ListingType;
use App\Enums\PriceUnit;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A `price_from` on its own is unreadable — a lodge rate here is as often per
 * person sharing as per room, and an activity's as often per group as per head
 * — so the trip plan was adding numbers that didn't mean the same thing. These
 * are the rules that keep the new column honest: it only ever says what someone
 * recorded, "unknown" survives every round trip as unknown, and the value
 * reaches the plan through every path a listing can enter one by.
 */
class ListingPriceUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unrecorded_unit_stays_unrecorded(): void
    {
        $listing = Listing::factory()->create(['type' => ListingType::Accommodation]);

        // Nothing infers "per night" from the type: a wrong unit is worse than
        // none, since only a recorded one is allowed to change the arithmetic.
        $this->assertNull($listing->fresh()->price_unit);
    }

    public function test_the_search_endpoint_carries_the_unit(): void
    {
        // The path a swap takes into a plan (ListingSwapModal): losing the unit
        // here would leave the swapped-in listing's price unqualified in the
        // plan even though the catalog knows what it is.
        Listing::factory()->create([
            'name' => 'Per Person Lodge',
            'type' => ListingType::Accommodation,
            'is_published' => true,
            'price_from' => 950,
            'price_unit' => PriceUnit::PerPersonPerNight,
        ]);

        $this->getJson('/listings/search?keyword=Per Person Lodge')
            ->assertOk()
            ->assertJsonPath('data.0.price_unit', 'per_person_per_night');
    }

    public function test_the_preview_endpoint_carries_the_unit(): void
    {
        $listing = Listing::factory()->create([
            'type' => ListingType::Activity,
            'is_published' => true,
            'price_from' => 450,
            'price_unit' => PriceUnit::PerPerson,
        ]);

        $this->getJson("/listings/{$listing->slug}/preview")
            ->assertOk()
            ->assertJsonPath('listing.price_unit', 'per_person');
    }

    public function test_the_public_api_carries_the_unit(): void
    {
        $listing = Listing::factory()->create([
            'type' => ListingType::Vehicle,
            'is_published' => true,
            'price_from' => 1200,
            'price_unit' => PriceUnit::PerDay,
        ]);

        $this->getJson("/api/v1/listings/{$listing->slug}")
            ->assertOk()
            ->assertJsonPath('data.price_unit', 'per_day');
    }

    public function test_an_owner_can_set_and_then_clear_the_unit(): void
    {
        $partner = Partner::create(['name' => 'Test Lodge', 'claim_token' => 'owner-token']);
        $listing = Listing::factory()->for($partner)->create([
            'type' => ListingType::Accommodation,
            'name' => 'Test Lodge',
        ]);

        $this->put(route('listings.update', $listing), [
            'name' => 'Test Lodge',
            'price_from' => '1400',
            'price_unit' => 'per_person_per_night',
            'preview' => 'owner-token',
        ])->assertRedirect();

        $this->assertSame(PriceUnit::PerPersonPerNight, $listing->fresh()->price_unit);

        // "I don't actually know" has to be re-selectable: the field's whole
        // point is that it never states something nobody confirmed, and the
        // update path drops nulls everywhere else so this needs its own answer.
        // An empty select arrives as null (ConvertEmptyStringsToNull).
        $this->put(route('listings.update', $listing), [
            'name' => 'Test Lodge',
            'price_from' => '1400',
            'price_unit' => '',
            'preview' => 'owner-token',
        ])->assertRedirect();

        $this->assertNull($listing->fresh()->price_unit);
    }

    public function test_an_invented_unit_is_rejected(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $listing = Listing::factory()->create(['name' => 'Test Lodge']);

        $this->actingAs($user)
            ->put(route('listings.update', $listing), [
                'name' => 'Test Lodge',
                'price_unit' => 'per_fortnight',
            ])
            ->assertSessionHasErrors('price_unit');
    }

    public function test_the_offered_units_fit_the_listing_type(): void
    {
        // Both admin panels and the self-service editor build their select from
        // this, so it's the one place that decides a lodge can't be quoted
        // "per booking" — which would silently break the per-night arithmetic
        // the plan does with a stay.
        $accommodation = PriceUnit::forType(ListingType::Accommodation);

        $this->assertSame(
            [PriceUnit::PerNight, PriceUnit::PerPersonPerNight],
            $accommodation,
        );

        foreach (ListingType::cases() as $type) {
            $this->assertNotEmpty(PriceUnit::forType($type));
        }
    }

    public function test_per_person_units_are_the_ones_that_multiply(): void
    {
        // The frontend mirrors this split (resources/js/lib/price-unit.ts) to
        // decide what a party of four actually pays — a per-room rate must not
        // be multiplied by heads, a per-person one must.
        foreach (PriceUnit::cases() as $unit) {
            $this->assertSame(
                str_contains($unit->value, 'per_person'),
                $unit->isPerPerson(),
                "{$unit->value} is on the wrong side of the per-person split",
            );
        }

        // Only the one-off units stop repeating — the vehicle line is the one
        // the plan multiplies by the trip length itself.
        $this->assertFalse(PriceUnit::PerBooking->isRecurring());
        $this->assertFalse(PriceUnit::PerPerson->isRecurring());
        $this->assertTrue(PriceUnit::PerDay->isRecurring());
        $this->assertTrue(PriceUnit::PerPersonPerNight->isRecurring());
    }
}
