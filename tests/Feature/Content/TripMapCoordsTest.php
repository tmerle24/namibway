<?php

namespace Tests\Feature\Content;

use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The index the trip map resolves a day's location against.
 *
 * It was destinations plus political regions, and a day's location has been a
 * *place* since 2026-08-23 — so "Sesriem" resolved to nothing, the stage was
 * dropped from the map, and the driving leg was then drawn between the two
 * stages either side of it. That is how a plan with Spitzkoppe between
 * Sossusvlei and Swakopmund showed a single 8h41 leg: not a bad route, a
 * missing waypoint.
 */
class TripMapCoordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_place_with_coordinates_can_be_resolved(): void
    {
        Place::query()->update(['lat' => -22.0, 'lng' => 17.0]);

        $coords = $this->getJson('/kaia/region-coords')->assertOk()->json('coords');

        // The route stops a generated plan actually names.
        foreach (['sesriem', 'swakopmund', 'twyfelfontein', 'etosha national park',
            'spitzkoppe', 'solitaire', 'cape cross', 'mariental', 'keetmanshoop'] as $key) {
            $this->assertArrayHasKey($key, $coords, "The map cannot place {$key}, so its stage is dropped.");
        }
    }

    public function test_a_place_without_coordinates_is_simply_absent(): void
    {
        Place::query()->update(['lat' => -22.0, 'lng' => 17.0]);
        // Solitaire on purpose: Spitzkoppe would still resolve, because it is
        // also a destination card and those carry their own coordinates.
        Place::where('slug', 'solitaire')->update(['lat' => null, 'lng' => null]);

        $coords = $this->getJson('/kaia/region-coords')->assertOk()->json('coords');

        // Not an invented value: a marker in the wrong place is worse than no
        // marker. namibway:backfill-place-coordinates is what fills these.
        $this->assertArrayNotHasKey('solitaire', $coords);
    }

    public function test_a_curated_destination_photo_still_wins_over_a_bare_place(): void
    {
        Place::query()->update(['lat' => -22.0, 'lng' => 17.0]);

        $coords = $this->getJson('/kaia/region-coords')->assertOk()->json('coords');

        // "Sossusvlei" is both a destination card and a place; the card has a
        // photo the place row has not.
        $this->assertArrayHasKey('sossusvlei', $coords);
        $this->assertSame('Sossusvlei', $coords['sossusvlei']['area']);
    }
}
