<?php

namespace Tests\Feature\Content;

use App\Models\Attraction;
use App\Models\Place;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a traveller passes between two stages.
 *
 * A Namibian leg is two to five hours, and the things beside that road are
 * exactly what gets driven past by somebody who did not know they were there.
 * The rules that decide what counts as "on the way" are the whole feature, so
 * they are asserted one at a time rather than through one happy path.
 *
 * The synthetic geography sits out in the eastern Kalahari, where the seeded
 * catalog has nothing, so a real attraction can never make one of these pass
 * or fail by accident. One test at the end runs on the real catalog instead —
 * the rules being right is not the same as the answer being useful.
 */
class RouteStopsTest extends TestCase
{
    use RefreshDatabase;

    /** A degree of longitude is ~101.7 km at this latitude, which is where the round numbers below come from. */
    private const LAT = -24.0;

    private function place(string $name, float $lat, float $lng): Place
    {
        return Place::create([
            'name' => ['en' => $name],
            'slug' => str($name)->slug()->value(),
            'type' => 'town',
            'region_id' => Region::query()->firstOrFail()->id,
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    private function attraction(string $name, float $lat, float $lng, array $attributes = []): Attraction
    {
        return Attraction::factory()->create([
            'name' => ['en' => $name],
            'slug' => str($name)->slug()->value(),
            'lat' => $lat,
            'lng' => $lng,
        ] + $attributes);
    }

    /** @return array<int, string> the stop names of leg $index, in the order returned */
    private function stopsOn(array $legs, int $index = 0): array
    {
        $query = [];

        foreach ($legs as $i => [$from, $to]) {
            $query["legs[{$i}][from]"] = $from;
            $query["legs[{$i}][to]"] = $to;
        }

        $body = $this->getJson('/attractions/along-route?'.http_build_query($query))
            ->assertOk()
            ->json();

        return array_column($body['legs'][$index]['stops'], 'name');
    }

    /** A 102 km leg due east, with room either side of it. */
    private function aHundredKilometreLeg(): void
    {
        $this->place('Testfontein', self::LAT, 20.0);
        $this->place('Testkop', self::LAT, 21.0);
    }

    public function test_it_offers_what_stands_beside_the_road_in_travel_order(): void
    {
        $this->aHundredKilometreLeg();

        // Both on the way; the second is further from the start, so it is the
        // second thing the traveller comes to.
        $this->attraction('Midway Pan', self::LAT, 20.5);
        $this->attraction('Sidetrack Ruin', -24.15, 20.7);

        $this->assertSame(
            ['Midway Pan', 'Sidetrack Ruin'],
            $this->stopsOn([['Testfontein', 'Testkop']]),
        );
    }

    public function test_a_long_way_off_the_road_is_not_a_short_stop(): void
    {
        $this->aHundredKilometreLeg();
        $this->attraction('Far Kloof', -25.0, 20.5);

        $this->assertSame([], $this->stopsOn([['Testfontein', 'Testkop']]));
    }

    /**
     * The detour rule alone would let this through: on a long enough leg, a
     * 40 km detour reaches a hundred kilometres to the side. At that distance
     * the straight line is not an approximation of any road, so the corridor
     * is what says "beside this road" rather than "cheap to reach from it".
     */
    public function test_far_to_the_side_of_a_long_leg_is_a_different_road(): void
    {
        // ~500 km due east, which is the length at which a cheap detour stops
        // meaning anything.
        $this->place('Testfontein', self::LAT, 20.0);
        $this->place('Testverre', self::LAT, 24.9);

        // About 87 km off the line at the midpoint, which costs under 30 km of
        // detour — comfortably inside the allowance, and nowhere near this road.
        $this->attraction('Sideways Pan', -24.8, 22.45);

        $this->assertSame([], $this->stopsOn([['Testfontein', 'Testverre']]));
    }

    public function test_something_at_either_end_belongs_to_that_stage_not_to_the_drive(): void
    {
        $this->aHundredKilometreLeg();
        $this->attraction('Testfontein Museum', -24.05, 20.05);
        $this->attraction('Testkop Lookout', self::LAT, 20.95);

        $this->assertSame([], $this->stopsOn([['Testfontein', 'Testkop']]));
    }

    /**
     * Coordinates are not enough on their own: a park's centroid can be 40 km
     * from the waterhole a traveller is spending two nights at, which is far
     * enough to pass the distance test and still be somewhere they are already
     * going.
     */
    public function test_something_filed_under_a_stage_is_never_a_stop_on_the_way_to_it(): void
    {
        $this->place('Testfontein', self::LAT, 20.0);
        $reserve = $this->place('Test Reserve', self::LAT, 21.0);

        $this->attraction('Reserve Waterhole', -24.2, 20.75, ['place_id' => $reserve->id]);

        $this->assertSame([], $this->stopsOn([['Testfontein', 'Test Reserve']]));
    }

    public function test_a_transfer_is_too_short_to_break(): void
    {
        $this->place('Testfontein', self::LAT, 20.0);
        $this->place('Testpoort', self::LAT, 20.45);

        // Far enough from both ends and barely any detour — it is only the
        // length of the drive that rules this out.
        $this->attraction('Halfway Cairn', -24.13, 20.24);

        $this->assertSame([], $this->stopsOn([['Testfontein', 'Testpoort']]));
    }

    public function test_a_round_trip_offers_each_stop_on_the_way_out(): void
    {
        $this->aHundredKilometreLeg();
        $this->attraction('Midway Pan', self::LAT, 20.5);

        $legs = [['Testfontein', 'Testkop'], ['Testkop', 'Testfontein']];

        $this->assertSame(['Midway Pan'], $this->stopsOn($legs, 0));
        $this->assertSame([], $this->stopsOn($legs, 1));
    }

    public function test_an_unpublished_one_is_offered_to_nobody(): void
    {
        $this->aHundredKilometreLeg();
        $this->attraction('Hidden Pan', self::LAT, 20.5, ['is_published' => false]);

        $this->assertSame([], $this->stopsOn([['Testfontein', 'Testkop']]));
    }

    public function test_it_says_roughly_what_the_stop_costs_in_extra_driving(): void
    {
        $this->aHundredKilometreLeg();
        $this->attraction('Midway Pan', self::LAT, 20.5);

        $stop = $this->getJson('/attractions/along-route?'.http_build_query([
            'legs[0][from]' => 'Testfontein',
            'legs[0][to]' => 'Testkop',
        ]))->assertOk()->json('legs.0.stops.0');

        // Straight on the line between the two, so no detour at all — and the
        // card shape is the one the rest of the plan already renders.
        $this->assertSame(0.0, (float) $stop['detour_km']);
        $this->assertSame('attraction', $stop['type']);
        $this->assertArrayHasKey('slug', $stop);
    }

    public function test_a_leg_it_cannot_place_is_answered_with_nothing_rather_than_an_error(): void
    {
        $this->aHundredKilometreLeg();
        $this->attraction('Midway Pan', self::LAT, 20.5);

        $this->assertSame([], $this->stopsOn([['Nowhere At All', 'Testkop']]));
    }

    public function test_it_refuses_a_request_that_names_no_leg(): void
    {
        $this->getJson('/attractions/along-route')->assertStatus(422);
    }

    /**
     * The rules being right is not the same as the answer being useful, so one
     * test runs on the catalog we actually ship: the drive down to the coast
     * should turn up the Moon Landscape, and must not turn up Heroes' Acre,
     * which is a Windhoek afternoon rather than a stop on the B2.
     */
    public function test_the_real_catalog_answers_the_drive_to_the_coast(): void
    {
        // Places are seeded without coordinates and geocoded afterwards.
        Place::where('slug', 'windhoek')->update(['lat' => -22.5609, 'lng' => 17.0658]);
        Place::where('slug', 'swakopmund')->update(['lat' => -22.6792, 'lng' => 14.5272]);

        $names = $this->stopsOn([['Windhoek', 'Swakopmund']]);

        $this->assertContains('Moon Landscape', $names);
        $this->assertNotContains("Heroes' Acre", $names);
        $this->assertNotContains('Christuskirche', $names);
    }
}
