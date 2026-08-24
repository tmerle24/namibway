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
            // Two day entries is a two-night trip: a `days` entry is a night,
            // and there is no separate entry for the departure day (see
            // ItineraryService::foldReturnDay).
            'nights' => 2,
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
        $this->fakeAnthropicDays([
            ['day' => 1, 'date' => '14 Aug 2026', 'location' => $day1City, 'accommodation' => "{$day1City} Lodge"],
            ['day' => 2, 'date' => '15 Aug 2026', 'location' => $day2City, 'accommodation' => "{$day2City} Lodge"],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     */
    private function fakeAnthropicDays(array $days): void
    {
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
                            'days' => $days,
                        ]],
                    ],
                ]],
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

    public function test_a_trailing_departure_day_is_folded_into_the_last_night(): void
    {
        // The bug this whole shape exists to stop: a `days` entry is a night,
        // so an entry for the departure day is rendered as a night the
        // traveler never sleeps — a trip running 14-16 Aug came back as three
        // nights checking out on the 17th, a day past its own end.
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();

        $this->seedLodge($windhoek);
        $this->fakeAnthropicDays([
            ['day' => 1, 'date' => '14 Aug 2026', 'location' => 'Windhoek', 'accommodation' => 'Windhoek Lodge'],
            ['day' => 2, 'date' => '15 Aug 2026', 'location' => 'Windhoek', 'accommodation' => 'Windhoek Lodge'],
            ['day' => 3, 'date' => '16 Aug 2026', 'location' => 'Windhoek', 'accommodation' => 'Windhoek Lodge', 'activity' => 'Windhoek Walk'],
        ]);

        $plan = app(ItineraryService::class)->generate($this->tripParams());

        // Folded, not retried and not thrown away: two nights for a two-night
        // trip, checking out on the departure day itself...
        Http::assertSentCount(1);
        $days = $plan['variants'][0]['days'];
        $this->assertCount(2, $days);
        $this->assertSame('16 Aug 2026', $days[1]['date_to']);

        // ...and what was planned for that morning survives as the last
        // night's departure entry, which is where the plan renders it.
        $this->assertSame('Windhoek Walk', $days[1]['departure_activities'][0]['name']);
    }

    public function test_a_plan_longer_than_one_stray_departure_day_still_triggers_a_corrective_retry(): void
    {
        // Two entries too many is not a departure day the model tacked on, it
        // is nights misallocated — nothing here can fold that into shape, so
        // it goes back to the model (2026-08-09: a Windhoek stage dated
        // "19-20 Jan" on a trip ending 18 Jan).
        $windhoek = City::where('slug', 'windhoek')->firstOrFail();

        $this->seedLodge($windhoek);
        $this->fakeAnthropicDays([
            ['day' => 1, 'date' => '14 Aug 2026', 'location' => 'Windhoek', 'accommodation' => 'Windhoek Lodge'],
            ['day' => 2, 'date' => '15 Aug 2026', 'location' => 'Windhoek', 'accommodation' => 'Windhoek Lodge'],
            ['day' => 3, 'date' => '16 Aug 2026', 'location' => 'Windhoek', 'accommodation' => 'Windhoek Lodge'],
            ['day' => 4, 'date' => '17 Aug 2026', 'location' => 'Windhoek', 'accommodation' => 'Windhoek Lodge'],
        ]);

        $plan = app(ItineraryService::class)->generate($this->tripParams());

        // The fake answers both calls identically, so the shape is never
        // actually corrected — and the plan is handed over anyway rather than
        // failing the request, because a wrong-but-editable plan beats a dead
        // end (see generate()).
        Http::assertSentCount(2);
        $this->assertCount(4, $plan['variants'][0]['days']);
    }
}
