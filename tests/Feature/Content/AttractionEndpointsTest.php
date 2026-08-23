<?php

namespace Tests\Feature\Content;

use App\Models\Attraction;
use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The trip plan needs two things from an attraction: a way to find one near
 * where the traveller is that day, and a way to look at one. It has no listing
 * page behind it, so both are its own.
 */
class AttractionEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function anchorEtosha(): void
    {
        // Places are seeded without coordinates and geocoded afterwards; the
        // search resolves "near" from them, so the fixture sets one.
        Place::where('slug', 'etosha-national-park')->update(['lat' => -19.1817, 'lng' => 15.92]);
    }

    public function test_search_offers_what_is_near_the_day_location(): void
    {
        $this->anchorEtosha();

        $body = $this->getJson('/attractions/search?reference_city=Etosha+National+Park')
            ->assertOk()
            ->json('data');

        $names = array_column($body, 'name');

        $this->assertContains('Okaukuejo Waterhole', $names);
        $this->assertNotContains('Kolmanskop Ghost Town', $names, 'Kolmanskop is 1,200 km away.');

        // Sorted by how far, because a traveller picking an activity for a day
        // is picking from what they can reach that day.
        $this->assertLessThanOrEqual($body[1]['distance_km'] ?? INF, $body[0]['distance_km']);
    }

    public function test_search_matches_on_a_keyword(): void
    {
        $names = array_column(
            $this->getJson('/attractions/search?keyword=meteorite')->assertOk()->json('data'),
            'name',
        );

        $this->assertSame(['Hoba Meteorite'], $names);
    }

    public function test_an_unpublished_one_is_offered_to_nobody(): void
    {
        Attraction::where('slug', 'hoba-meteorite')->update(['is_published' => false]);

        $this->getJson('/attractions/search?keyword=meteorite')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/attractions/hoba-meteorite')->assertNotFound();
    }

    public function test_the_detail_endpoint_carries_the_operational_facts(): void
    {
        $kolmanskop = $this->getJson('/attractions/kolmanskop-ghost-town')->assertOk()->json();

        $this->assertSame('Kolmanskop Ghost Town', $kolmanskop['name']);
        $this->assertSame(120, $kolmanskop['duration_minutes']);
        $this->assertTrue($kolmanskop['requires_permit']);

        // null is "not established", not "no" — the modal renders neither.
        $this->assertNull($kolmanskop['requires_4x4']);
    }

    public function test_the_search_result_keeps_the_shape_a_listing_result_has(): void
    {
        // One card design serves both lists in the activity picker, so the
        // keys it reads have to be present even where they can only be null.
        $first = $this->getJson('/attractions/search?keyword=meteorite')->assertOk()->json('data.0');

        foreach (['id', 'type', 'name', 'slug', 'image', 'gallery', 'city', 'region', 'area',
            'latitude', 'longitude', 'price_from', 'price_currency', 'price_unit',
            'duration_minutes', 'rating', 'rating_count', 'vehicle_category'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }

        $this->assertSame('attraction', $first['type']);
    }
}
