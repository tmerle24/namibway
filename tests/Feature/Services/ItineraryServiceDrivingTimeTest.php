<?php

namespace Tests\Feature\Services;

use App\Enums\ListingType;
use App\Models\City;
use App\Models\Listing;
use App\Models\Partner;
use App\Services\Kaia\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * ItineraryService::generate()'s driving-time safety check now validates at
 * place granularity (place_driving_hours, backed by real OSRM data — see
 * OsrmDrivingTimeService) instead of the old region-level DRIVING_HOURS
 * table. Regions/cities themselves are already fully seeded by the
 * create_regions_table/create_cities_table migrations (RefreshDatabase runs
 * every migration), so these tests use the real Windhoek/Swakopmund/
 * Okahandja rows rather than factory-created ones — only place_driving_hours
 * and the Anthropic response are test-specific.
 */
class ItineraryServiceDrivingTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-key']);
    }

    /**
     * end_location is a parameter because generate() now also enforces the
     * route's shape (validateRouteShape): a fixture whose last day is in
     * Okahandja must declare a one-way trip ending there, or the shape check
     * would trip before the driving-time behaviour under test is reached.
     */
    private function tripParams(string $end = 'Windhoek'): array
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
            'end_location' => $end,
        ];
    }

    private function fakeAnthropicPlan(string $day1City, string $day2City): void
    {
        $planInput = [
            'trip_summary' => 'A short test trip.',
            'variants' => [[
                'name' => 'Test Variant',
                'vehicle' => 'Test Vehicle',
                'days' => [
                    ['day' => 1, 'date' => '14 Aug 2026', 'location' => $day1City, 'accommodation' => "{$day1City} Lodge"],
                    ['day' => 2, 'date' => '15 Aug 2026', 'location' => $day2City, 'accommodation' => "{$day2City} Lodge"],
                ],
            ]],
        ];

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'tool_use', 'name' => 'propose_itinerary', 'input' => $planInput],
                ],
            ], 200),
        ]);
    }

    private function seedLodge(City $city): Listing
    {
        $partner = Partner::create(['name' => "{$city->name} Partner"]);

        return Listing::factory()->create([
            'type' => ListingType::Accommodation,
            'name' => "{$city->name} Lodge",
            'city_id' => $city->id,
            'partner_id' => $partner->id,
            'is_published' => true,
            'connector_property_code' => null,
        ]);
    }

    public function test_plan_within_the_city_driving_limit_passes_through(): void
    {
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();
        $okahandja = City::where('slug', 'okahandja')->firstOrFail();

        DB::table('place_driving_hours')->insert([
            'place_a_id' => min($windhoek->place_id, $okahandja->place_id),
            'place_b_id' => max($windhoek->place_id, $okahandja->place_id),
            'hours' => 0.9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedLodge($windhoek);
        $this->seedLodge($okahandja);
        $this->fakeAnthropicPlan('Windhoek', 'Okahandja');

        $plan = app(ItineraryService::class)->generate($this->tripParams(end: 'Okahandja'));

        $this->assertSame('Windhoek', $plan['variants'][0]['days'][0]['location']);
        $this->assertSame('Okahandja', $plan['variants'][0]['days'][1]['location']);
        Http::assertSentCount(1);
    }

    public function test_plan_exceeding_the_city_driving_limit_triggers_a_corrective_retry_and_fails_if_still_violating(): void
    {
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();
        $swakopmund = City::where('slug', 'swakopmund')->firstOrFail();

        // Deliberately over ItineraryService::MAX_DRIVING_HOURS (6.0), unlike the
        // real ~4h OSRM value — this test only needs a violation to exist.
        DB::table('place_driving_hours')->insert([
            'place_a_id' => min($windhoek->place_id, $swakopmund->place_id),
            'place_b_id' => max($windhoek->place_id, $swakopmund->place_id),
            'hours' => 9.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedLodge($windhoek);
        $this->seedLodge($swakopmund);
        $this->fakeAnthropicPlan('Windhoek', 'Swakopmund');

        $this->expectException(RuntimeException::class);

        try {
            app(ItineraryService::class)->generate($this->tripParams(end: 'Swakopmund'));
        } finally {
            // Initial attempt + one corrective retry — every call gets the same
            // fake response, so the violation is never actually resolved.
            Http::assertSentCount(2);
        }
    }

    public function test_plan_ignoring_the_start_city_triggers_a_corrective_retry(): void
    {
        // Production incident 2026-08-09: a Windhoek round trip whose plan
        // began in Otjiwarongo (Otjozondjupa) sailed through because only
        // driving times were validated, never the route's shape.
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();
        $otjiwarongo = City::where('slug', 'otjiwarongo')->firstOrFail();

        $this->seedLodge($windhoek);
        $this->seedLodge($otjiwarongo);
        $this->fakeAnthropicPlan('Otjiwarongo', 'Windhoek');

        $plan = app(ItineraryService::class)->generate($this->tripParams());

        // Initial attempt + one corrective retry. The fake answers both calls
        // identically, so the shape is never actually corrected — and unlike a
        // driving-time violation the plan is still handed over rather than
        // failing the request, because a wrong-but-editable plan beats a dead
        // end (see generate()).
        Http::assertSentCount(2);
        $this->assertSame('Otjiwarongo', $plan['variants'][0]['days'][0]['location']);
    }

    public function test_plan_with_too_many_days_triggers_a_corrective_retry(): void
    {
        // The visible symptom of an over-long plan is a stage dated past the
        // traveler's departure day (2026-08-09: "19–20 Jan" on a trip ending
        // 18 Jan) — a 1-night trip must have exactly 2 day entries.
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();

        $this->seedLodge($windhoek);

        $planInput = [
            'trip_summary' => 'A short test trip.',
            'variants' => [[
                'name' => 'Test Variant',
                'vehicle' => 'Test Vehicle',
                'days' => [
                    ['day' => 1, 'date' => '14 Aug 2026', 'location' => 'Windhoek', 'accommodation' => 'Windhoek Lodge'],
                    ['day' => 2, 'date' => '15 Aug 2026', 'location' => 'Windhoek', 'accommodation' => 'Windhoek Lodge'],
                    ['day' => 3, 'date' => '16 Aug 2026', 'location' => 'Windhoek', 'accommodation' => 'Windhoek Lodge'],
                ],
            ]],
        ];

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'tool_use', 'name' => 'propose_itinerary', 'input' => $planInput],
                ],
            ], 200),
        ]);

        $plan = app(ItineraryService::class)->generate($this->tripParams());

        Http::assertSentCount(2);
        $this->assertCount(3, $plan['variants'][0]['days']);
    }
}
