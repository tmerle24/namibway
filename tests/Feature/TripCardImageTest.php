<?php

namespace Tests\Feature;

use App\Enums\PlaceType;
use App\Models\Place;
use App\Models\Region;
use App\Models\SavedPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The picture a shared trip link unfurls to — see
 * App\Services\Trip\TripCardImage. Fetched by crawlers with no session, so
 * the route has to be open, and the URL has to carry the read-only token.
 */
class TripCardImageTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(): SavedPlan
    {
        $region = Region::factory()->create();

        // updateOrCreate, not create: the migrations seed Namibia's real
        // places, so these names are already there — the test only needs them
        // to sit at known coordinates.
        foreach ([['Windhoek', -22.56, 17.08], ['Sesriem', -24.49, 15.80], ['Etosha National Park', -18.85, 16.33]] as [$name, $lat, $lng]) {
            Place::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'type' => PlaceType::Town, 'region_id' => $region->id, 'lat' => $lat, 'lng' => $lng],
            );
        }

        return SavedPlan::create([
            'title' => 'Dunes and big game',
            'plan_json' => [
                'trip_summary' => 'Dunes and big game',
                'variants' => [['days' => [
                    ['day' => 1, 'location' => 'Windhoek', 'date' => '25 Aug 2026', 'date_to' => '26 Aug 2026'],
                    ['day' => 2, 'location' => 'Sesriem', 'date' => '26 Aug 2026', 'date_to' => '27 Aug 2026'],
                    ['day' => 3, 'location' => 'Etosha National Park', 'date' => '27 Aug 2026', 'date_to' => '28 Aug 2026'],
                    ['day' => 4, 'location' => 'Windhoek', 'date' => '28 Aug 2026', 'date_to' => '29 Aug 2026'],
                ]]],
            ],
        ]);
    }

    public function test_it_serves_a_png_of_the_size_the_meta_tags_promise(): void
    {
        $saved = $this->makePlan();

        $response = $this->get(route('trip.card', $saved->share_token))->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));

        $size = getimagesizefromstring((string) $response->getContent());

        $this->assertIsArray($size);
        $this->assertSame([1200, 630], [$size[0], $size[1]]);
    }

    public function test_the_page_points_at_the_card_with_the_read_only_token_even_from_the_edit_link(): void
    {
        $saved = $this->makePlan();

        $html = (string) $this->get(route('trip.show', $saved->token))->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:image" content="'.route('trip.card', $saved->share_token).'">',
            $html,
        );
        // The edit token would hand write access to everyone the picture
        // reaches — see the same rule on `shareUrl`.
        $this->assertStringNotContainsString(route('trip.card', $saved->token), $html);
    }

    public function test_the_alt_text_says_where_the_route_goes(): void
    {
        $saved = $this->makePlan();

        $this->get(route('trip.show', $saved->share_token))
            ->assertOk()
            ->assertSee('A map of the route Windhoek → Sesriem → Etosha National Park', false);
    }

    public function test_a_plan_whose_stops_cannot_be_placed_still_renders(): void
    {
        $saved = SavedPlan::create([
            'title' => null,
            'plan_json' => ['variants' => [['days' => [['day' => 1, 'location' => 'Nowhere']]]]],
        ]);

        $size = getimagesizefromstring(
            (string) $this->get(route('trip.card', $saved->share_token))->assertOk()->getContent()
        );

        $this->assertIsArray($size);
        $this->assertSame([1200, 630], [$size[0], $size[1]]);
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get(route('trip.card', 'nope'))->assertNotFound();
    }
}
