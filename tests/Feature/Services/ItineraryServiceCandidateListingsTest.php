<?php

namespace Tests\Feature\Services;

use App\Enums\ListingType;
use App\Models\City;
use App\Models\Listing;
use App\Models\Partner;
use App\Services\Kaia\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * candidateListings() picks its shortlist off a `toBase()` query — plain rows,
 * no Eloquent models — because hydrating the whole published catalog to keep at
 * most 20 per type exhausted PHP's memory limit and took /kaia/message down on
 * 2026-08-09.
 *
 * That makes the filters read raw column values instead of going through the
 * model, which is the part that can silently rot: a camper check that always
 * answered "no" would not throw, it would just quietly hand campers-only
 * travelers the wrong vehicles. These tests pin the raw-value behaviour.
 */
class ItineraryServiceCandidateListingsTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $attributes */
    private function makeListing(ListingType $type, string $name, array $attributes = []): Listing
    {
        $partner = Partner::create(['name' => $name.' Partner']);

        return Listing::factory()->create([
            'type' => $type,
            'name' => $name,
            'city_id' => City::where('slug', 'windhoek')->firstOrFail()->id,
            'partner_id' => $partner->id,
            'is_published' => true,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $tripParams
     * @return Collection<int, Listing>
     */
    private function candidates(array $tripParams): Collection
    {
        $method = new ReflectionMethod(ItineraryService::class, 'candidateListings');

        /** @var Collection<int, Listing> $result */
        $result = $method->invoke(app(ItineraryService::class), $tripParams);

        return $result;
    }

    public function test_it_shortlists_only_campers_for_a_camper_trip(): void
    {
        $camper = $this->makeListing(ListingType::Vehicle, 'Bushtrack Camper 4x4', [
            'highlights' => ['Camper', '4x4'],
        ]);
        $this->makeListing(ListingType::Vehicle, 'Plain Sedan', [
            'highlights' => ['Air conditioning'],
        ]);

        $names = $this->candidates(['vehicle_type' => 'camper'])
            ->where('type', ListingType::Vehicle)
            ->pluck('name');

        $this->assertEquals([$camper->name], $names->values()->all());
    }

    public function test_it_shortlists_only_non_campers_for_a_car_trip(): void
    {
        $this->makeListing(ListingType::Vehicle, 'Bushtrack Camper 4x4', [
            'highlights' => ['Camper', '4x4'],
        ]);
        $car = $this->makeListing(ListingType::Vehicle, 'Plain Sedan', [
            'highlights' => ['Air conditioning'],
        ]);

        $names = $this->candidates(['vehicle_type' => 'car'])
            ->where('type', ListingType::Vehicle)
            ->pluck('name');

        $this->assertEquals([$car->name], $names->values()->all());
    }

    public function test_a_vehicle_without_highlights_counts_as_not_a_camper(): void
    {
        $bare = $this->makeListing(ListingType::Vehicle, 'Undescribed Bakkie', [
            'highlights' => null,
        ]);

        $this->assertEquals(
            [$bare->name],
            $this->candidates(['vehicle_type' => 'car'])->pluck('name')->values()->all(),
        );

        $this->assertEmpty($this->candidates(['vehicle_type' => 'camper'])->all());
    }

    public function test_it_keeps_listings_within_one_budget_tier_and_drops_the_rest(): void
    {
        // budgetTier(): < 150 budget, <= 400 mid-range, above premium. Asking
        // for budget therefore keeps mid-range (distance 1) but not premium (2).
        $cheap = $this->makeListing(ListingType::Accommodation, 'Cheap Rest Camp', ['price_from' => 100]);
        $middle = $this->makeListing(ListingType::Accommodation, 'Middling Lodge', ['price_from' => 300]);
        $this->makeListing(ListingType::Accommodation, 'Grand Luxury Lodge', ['price_from' => 900]);

        $names = $this->candidates(['budget_tier' => 'budget'])->pluck('name')->values()->all();

        $this->assertEqualsCanonicalizing([$cheap->name, $middle->name], $names);
    }

    public function test_it_returns_full_models_for_the_shortlisted_rows(): void
    {
        // The shortlist pass reads raw rows, but what comes back has to be
        // usable models with their city relation loaded — resolveReferences()
        // and backfillAccommodation() both rely on it.
        $listing = $this->makeListing(ListingType::Accommodation, 'Windhoek Lodge', ['price_from' => 100]);

        $candidate = $this->candidates(['budget_tier' => 'budget'])->firstOrFail();

        $this->assertSame($listing->id, $candidate->id);
        $this->assertSame('Windhoek Lodge', $candidate->getTranslation('name', 'en', useFallbackLocale: true));
        $this->assertTrue($candidate->relationLoaded('city'));
        $this->assertSame('Windhoek', $candidate->city?->name);
    }

    public function test_it_leaves_unpublished_listings_out(): void
    {
        $this->makeListing(ListingType::Accommodation, 'Hidden Lodge', [
            'price_from' => 100,
            'is_published' => false,
        ]);

        $this->assertEmpty($this->candidates([])->all());
    }
}
