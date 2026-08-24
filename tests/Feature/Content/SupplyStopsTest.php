<?php

namespace Tests\Feature\Content;

use App\Enums\SupplyService;
use App\Models\Listing;
use App\Models\Place;
use App\Models\Region;
use App\Models\SupplyPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where to fill up, and where to buy food, before the road stops offering it.
 *
 * The rule is the feature — a list of filling stations is not — so each part
 * of it is asserted on its own: a stop is named for the emptiness *after* it,
 * the pumps in the town you are sleeping in belong to the drive that leaves
 * rather than the one that arrived, and a supermarket matters for a
 * self-catering night however short the drive to it was.
 *
 * The synthetic geography sits out in the eastern Kalahari, where the seeded
 * corpus has nothing, so a real filling station can never make one of these
 * pass or fail by accident. A degree of longitude is ~101.7 km at this
 * latitude, which is where the round numbers come from.
 */
class SupplyStopsTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = -24.0;

    private function place(string $name, float $lng): Place
    {
        return Place::create([
            'name' => ['en' => $name],
            'slug' => str($name)->slug()->value(),
            'type' => 'town',
            'region_id' => Region::query()->firstOrFail()->id,
            'lat' => self::LAT,
            'lng' => $lng,
        ]);
    }

    /**
     * @param  array<int, SupplyService>  $services
     * @param  array<string, mixed>  $attributes
     */
    private function supply(string $name, float $lng, array $services = [SupplyService::Fuel], float $lat = self::LAT, array $attributes = []): SupplyPoint
    {
        return SupplyPoint::factory()->create([
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'services' => $services,
            'lat' => $lat,
            'lng' => $lng,
        ] + $attributes);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $legs  [from, to, stay slug]
     * @return array<int, string> the names named on leg $index
     */
    private function stopsOn(array $legs, int $index = 0): array
    {
        return array_column($this->response($legs)['legs'][$index]['stops'], 'name');
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $legs
     * @return array<string, mixed>
     */
    private function response(array $legs): array
    {
        $query = [];

        foreach ($legs as $i => $leg) {
            $query["legs[{$i}][from]"] = $leg[0];
            $query["legs[{$i}][to]"] = $leg[1];

            if (isset($leg[2])) {
                $query["legs[{$i}][stay_slug]"] = $leg[2];
            }
        }

        return $this->getJson('/supply-stops/along-route?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    /** A 305 km leg due east. */
    private function aLongLeg(): void
    {
        $this->place('Testfontein', 20.0);
        $this->place('Testkop', 23.0);
    }

    /**
     * The whole rule in one case: what gets named is the last place with fuel
     * before the empty stretch, and the three others are silent precisely
     * because there is another one a few kilometres later. Windhoek has thirty
     * forecourts and none of them is news.
     */
    public function test_only_the_last_one_before_the_empty_stretch_is_named(): void
    {
        $this->aLongLeg();

        $this->supply('Alpha Pumps', 20.0);
        $this->supply('Beta Pumps', 20.2);
        $this->supply('Gamma Pumps', 20.5);
        $this->supply('Omega Pumps', 23.0);

        $this->assertSame(['Gamma Pumps'], $this->stopsOn([['Testfontein', 'Testkop']]));
    }

    public function test_it_says_how_far_the_road_ahead_goes_without_any(): void
    {
        $this->aLongLeg();
        $this->supply('Gamma Pumps', 20.5);
        $this->supply('Omega Pumps', 23.0);

        $stop = $this->response([['Testfontein', 'Testkop']])['legs'][0]['stops'][0];

        $this->assertSame('fuel', $stop['reasons'][0]['service']);
        // ~254 km of straight line between the two, and a lower bound on what
        // the road does — see SupplyStopFinder.
        $this->assertEqualsWithDelta(254, $stop['reasons'][0]['gap_km'], 2);
        $this->assertFalse($stop['reasons'][0]['before_self_catering']);
    }

    /**
     * The pumps in the town you are sleeping in are not a stop on the drive
     * that ended there; they are the reason the next drive is survivable. A
     * supply point at a shared stage matches both legs on purpose, and only
     * the one that leaves is worth telling anybody about.
     */
    public function test_a_stop_at_a_stage_belongs_to_the_drive_that_leaves_it(): void
    {
        $this->place('Testfontein', 20.0);
        $this->place('Testberg', 21.0);
        $this->place('Testverre', 23.5);

        $this->supply('Alpha Pumps', 20.0);
        $this->supply('Berg Pumps', 21.0);

        $legs = [['Testfontein', 'Testberg'], ['Testberg', 'Testverre']];

        $this->assertSame([], $this->stopsOn($legs, 0));
        $this->assertSame(['Berg Pumps'], $this->stopsOn($legs, 1));
    }

    /**
     * Unlike an attraction, which is offered once because seeing it twice is
     * pointless. Filling up twice is the entire idea.
     */
    public function test_a_route_that_comes_back_through_a_town_names_it_again(): void
    {
        $this->place('Testfontein', 20.0);
        $this->place('Testkop', 22.0);
        $this->place('Testwes', 18.0);

        $this->supply('Alpha Pumps', 20.0);

        $legs = [
            ['Testfontein', 'Testkop'],
            ['Testkop', 'Testfontein'],
            ['Testfontein', 'Testwes'],
        ];

        $this->assertSame(['Alpha Pumps'], $this->stopsOn($legs, 0));
        $this->assertSame([], $this->stopsOn($legs, 1));
        $this->assertSame(['Alpha Pumps'], $this->stopsOn($legs, 2));
    }

    /**
     * Distance is not what makes a supermarket matter. Three nights of cooking
     * your own dinner is, and the drive to it can be an hour.
     */
    public function test_the_last_shop_before_a_self_catering_stay_is_named_however_short_the_drive(): void
    {
        $this->place('Testfontein', 20.0);
        $this->place('Testkamp', 20.6);

        $this->supply('Alpha Store', 20.0, [SupplyService::Groceries]);

        $stay = Listing::factory()->create([
            'slug' => 'kalahari-self-catering',
            'highlights' => ['Self-catering chalets', 'Braai facilities'],
        ]);

        $stop = $this->response([['Testfontein', 'Testkamp', $stay->slug]])['legs'][0]['stops'][0];

        $this->assertSame('Alpha Store', $stop['name']);
        $this->assertSame('groceries', $stop['reasons'][0]['service']);
        $this->assertTrue($stop['reasons'][0]['before_self_catering']);
    }

    public function test_a_stay_that_feeds_its_guests_is_not_a_reason_to_stop_for_food(): void
    {
        $this->place('Testfontein', 20.0);
        $this->place('Testkamp', 20.6);

        $this->supply('Alpha Store', 20.0, [SupplyService::Groceries]);

        $stay = Listing::factory()->create([
            'slug' => 'kalahari-lodge',
            'highlights' => ['Restaurant & bar', 'Full board'],
        ]);

        $this->assertSame([], $this->stopsOn([['Testfontein', 'Testkamp', $stay->slug]]));
    }

    /** The camp store across the road is not a reason to load the car 60 km earlier. */
    public function test_a_self_catering_stay_with_a_shop_of_its_own_names_nothing(): void
    {
        $this->place('Testfontein', 20.0);
        $this->place('Testkamp', 20.6);

        $this->supply('Alpha Store', 20.0, [SupplyService::Groceries]);
        $this->supply('Kamp Store', 20.6, [SupplyService::Groceries]);

        $stay = Listing::factory()->create([
            'slug' => 'kalahari-self-catering',
            'highlights' => ['Self-catering chalets'],
        ]);

        $this->assertSame([], $this->stopsOn([['Testfontein', 'Testkamp', $stay->slug]]));
    }

    public function test_a_pump_a_hundred_kilometres_to_the_side_is_on_a_different_road(): void
    {
        $this->aLongLeg();
        $this->supply('Far Pumps', 21.5, [SupplyService::Fuel], -25.0);

        $this->assertSame([], $this->stopsOn([['Testfontein', 'Testkop']]));
    }

    public function test_an_unpublished_one_is_named_to_nobody(): void
    {
        $this->aLongLeg();
        $this->supply('Gamma Pumps', 20.5, [SupplyService::Fuel], self::LAT, ['is_published' => false]);
        $this->supply('Omega Pumps', 23.0);

        $this->assertSame([], $this->stopsOn([['Testfontein', 'Testkop']]));
    }

    /**
     * A leg whose ends cannot be placed is a hole in the route, and a gap
     * measured across a hole is a claim about a stretch of road we know
     * nothing about. The same fuel is named when the route ends where it can
     * be measured, and silent when it does not.
     */
    public function test_a_gap_is_never_measured_across_a_leg_it_cannot_place(): void
    {
        $this->aLongLeg();
        $this->supply('Alpha Pumps', 20.0);

        $this->assertSame(['Alpha Pumps'], $this->stopsOn([['Testfontein', 'Testkop']]));

        $this->assertSame([], $this->stopsOn([
            ['Testfontein', 'Testkop'],
            ['Nowhere At All', 'Nowhere Else'],
        ]));
    }

    public function test_it_carries_what_decides_whether_the_stop_is_any_use(): void
    {
        $this->aLongLeg();
        $this->supply('Gamma Pumps', 20.5, [SupplyService::Fuel, SupplyService::Groceries], self::LAT, [
            'fuel_types' => ['petrol', 'diesel'],
            'opening_hours' => '24/7',
            'note' => ['en' => 'Cash only after dark.'],
            'verified_at' => now(),
        ]);
        $this->supply('Omega Pumps', 23.0);

        $stop = $this->response([['Testfontein', 'Testkop']])['legs'][0]['stops'][0];

        $this->assertSame(['fuel', 'groceries'], $stop['services']);
        $this->assertSame(['petrol', 'diesel'], $stop['fuel_types']);
        $this->assertTrue($stop['opening_hours']['always_open']);
        $this->assertSame('Cash only after dark.', $stop['note']);
        $this->assertTrue($stop['verified']);
        $this->assertSame(0, $stop['detour_km']);
    }

    public function test_hours_nobody_recorded_are_absent_rather_than_invented(): void
    {
        $this->aLongLeg();
        $this->supply('Gamma Pumps', 20.5);
        $this->supply('Omega Pumps', 23.0);

        $stop = $this->response([['Testfontein', 'Testkop']])['legs'][0]['stops'][0];

        $this->assertNull($stop['opening_hours']);
        $this->assertFalse($stop['verified']);
    }

    public function test_it_refuses_a_request_that_names_no_leg(): void
    {
        $this->getJson('/supply-stops/along-route')->assertStatus(422);
    }

    /**
     * The corpus that ships with the migration. Not the rule this time but the
     * data: a locator slug that matches nothing is a row that silently never
     * exists, and the transliterated ones are where that happens first.
     */
    public function test_the_seeded_corpus_is_filed_against_a_real_town_or_place(): void
    {
        // A locator slug that matches nothing is skipped in silence, so the
        // count is the only thing that notices a whole road going missing.
        $this->assertGreaterThanOrEqual(50, SupplyPoint::query()->count());

        foreach (['solitaire', 'kamanjab', 'luderitz', 'maltahohe', 'katima-mulilo'] as $slug) {
            $this->assertTrue(
                SupplyPoint::query()->where('slug', $slug)->exists(),
                "The seed lost {$slug} — its locator slug matches no city or place.",
            );
        }

        // The two inside Etosha carry their own coordinates, because a park is
        // not a town and its centroid is nowhere near either camp.
        $this->assertTrue(SupplyPoint::query()->where('slug', 'okaukuejo')->firstOrFail()->isRoutable());
    }

    /**
     * Everything else is located by the town it is in, which is geocoded by a
     * command of its own — so the seed can only copy what is there at the time,
     * and this is the second half of it.
     */
    public function test_the_backfill_gives_a_supply_point_the_coordinates_of_its_town(): void
    {
        $outjo = SupplyPoint::query()->where('slug', 'outjo')->firstOrFail();

        $this->assertFalse($outjo->isRoutable());

        Place::query()->where('slug', 'outjo')->update(['lat' => -20.1064, 'lng' => 16.1500]);

        $this->artisan('namibway:backfill-supply-point-coordinates')->assertSuccessful();

        $outjo->refresh();

        $this->assertEqualsWithDelta(-20.1064, (float) $outjo->lat, 0.0001);
        $this->assertEqualsWithDelta(16.15, (float) $outjo->lng, 0.0001);
    }

    /** …and never over a coordinate somebody sharpened to the actual forecourt. */
    public function test_the_backfill_never_overwrites_a_coordinate_somebody_set(): void
    {
        Place::query()->where('slug', 'outjo')->update(['lat' => -20.1064, 'lng' => 16.1500]);

        SupplyPoint::query()->where('slug', 'outjo')->update(['lat' => -20.11, 'lng' => 16.16]);

        $this->artisan('namibway:backfill-supply-point-coordinates')->assertSuccessful();

        $this->assertEqualsWithDelta(
            -20.11,
            (float) SupplyPoint::query()->where('slug', 'outjo')->firstOrFail()->lat,
            0.0001,
        );
    }
}
