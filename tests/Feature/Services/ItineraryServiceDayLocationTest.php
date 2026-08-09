<?php

namespace Tests\Feature\Services;

use App\Enums\ListingType;
use App\Models\City;
use App\Models\Listing;
use App\Models\Partner;
use App\Services\Kaia\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A day's `location` heads its stage card, its map popup and its driving-time
 * check, and the traveler is meant to read a city there — never a political
 * region. The prompt asks for exactly that, but a prompt is a request, so
 * ItineraryService::normalizeDayLocations() resolves it deterministically
 * afterwards. These tests pin that down by making Claude answer with region
 * names, the way it periodically does.
 *
 * Regions/cities are seeded by the create_regions_table/create_cities_table
 * migrations, so the real Windhoek/Rehoboth rows are used rather than
 * factory-made ones — only the listings and the Anthropic response are
 * test-specific.
 */
class ItineraryServiceDayLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-key']);
    }

    /**
     * @return array<string, mixed>
     */
    private function tripParams(): array
    {
        return [
            'nights' => 1,
            'travel_period' => '14 August 2026',
            'interests' => 'wildlife',
            'adults' => 2,
            'children_under_13' => 0,
            'vehicle_type' => 'car',
            'budget_tier' => 'mid-range',
            'start_location' => 'Windhoek',
            'end_location' => 'Windhoek',
        ];
    }

    private function fakeAnthropicPlan(string $location, ?string $accommodation): void
    {
        $day = fn (int $number, string $date) => array_filter([
            'day' => $number,
            'date' => $date,
            'location' => $location,
            'accommodation' => $accommodation,
        ], fn ($value) => $value !== null);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'tool_use',
                    'name' => 'propose_itinerary',
                    'input' => [
                        'trip_summary' => 'A short test trip.',
                        'variants' => [[
                            'name' => 'Test Variant',
                            'vehicle' => 'Test Vehicle',
                            'days' => [$day(1, '14 Aug 2026'), $day(2, '15 Aug 2026')],
                        ]],
                    ],
                ]],
            ], 200),
        ]);
    }

    private function seedLodge(City $city, string $name): Listing
    {
        $partner = Partner::create(['name' => "{$name} Partner"]);

        return Listing::factory()->create([
            'type' => ListingType::Accommodation,
            'name' => $name,
            'city_id' => $city->id,
            'partner_id' => $partner->id,
            'is_published' => true,
            'connector_property_code' => null,
        ]);
    }

    public function test_a_region_location_is_replaced_by_the_accommodation_city(): void
    {
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();
        $this->seedLodge($windhoek, 'Windhoek Lodge');

        $this->fakeAnthropicPlan('Khomas', 'Windhoek Lodge');

        $plan = app(ItineraryService::class)->generate($this->tripParams());

        foreach ($plan['variants'][0]['days'] as $day) {
            $this->assertSame('Windhoek', $day['location']);
            $this->assertSame('Khomas', $day['region']);
        }
    }

    public function test_a_region_location_with_no_usable_accommodation_falls_back_to_the_regions_busiest_city(): void
    {
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();
        $dordabis = City::where('slug', 'dordabis')->firstOrFail();

        // Both are in Khomas; Windhoek carries more published listings, so it
        // is the region's hub and the stand-in the fallback should pick.
        $this->seedLodge($windhoek, 'Windhoek Lodge');
        $this->seedLodge($windhoek, 'Windhoek Guesthouse');
        $this->seedLodge($dordabis, 'Dordabis Rest Camp');

        $this->fakeAnthropicPlan('Khomas', null);

        $plan = app(ItineraryService::class)->generate($this->tripParams());

        foreach ($plan['variants'][0]['days'] as $day) {
            $this->assertSame('Windhoek', $day['location']);
            $this->assertSame('Khomas', $day['region']);
        }
    }

    public function test_a_city_location_keeps_its_city_and_gains_its_region(): void
    {
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();
        $this->seedLodge($windhoek, 'Windhoek Lodge');

        $this->fakeAnthropicPlan('Windhoek', 'Windhoek Lodge');

        $plan = app(ItineraryService::class)->generate($this->tripParams());

        foreach ($plan['variants'][0]['days'] as $day) {
            $this->assertSame('Windhoek', $day['location']);
            $this->assertSame('Khomas', $day['region']);
        }
    }

    public function test_an_unresolvable_location_is_left_alone_rather_than_blanked(): void
    {
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();
        $this->seedLodge($windhoek, 'Windhoek Lodge');

        // A park name — what the prompt tells Claude never to use, and what
        // no City row matches. Nothing to resolve it to, so it stays put.
        $this->fakeAnthropicPlan('Etosha', 'Nowhere Lodge');

        $plan = app(ItineraryService::class)->generate($this->tripParams());

        foreach ($plan['variants'][0]['days'] as $day) {
            $this->assertSame('Etosha', $day['location']);
            $this->assertNull($day['region']);
        }
    }
}
