<?php

namespace Tests\Feature;

use App\Models\SavedPlan;
use App\Support\TripPlanMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A `/trip/{token}` link exists to be sent to somebody, so the card WhatsApp
 * and friends draw from it has to describe the plan — the recipient sees the
 * card before they see the page. See App\Support\TripPlanMeta.
 */
class TripPlanLinkPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(): SavedPlan
    {
        return SavedPlan::create([
            'title' => 'Dunes, desert and wildlife in twelve days',
            'plan_json' => [
                'trip_summary' => 'Dunes, desert and wildlife in twelve days',
                'variants' => [[
                    'name' => 'Classic',
                    'days' => [
                        ['day' => 1, 'location' => 'Windhoek', 'date' => '25 Aug 2026', 'date_to' => '26 Aug 2026'],
                        ['day' => 2, 'location' => 'Sesriem', 'date' => '26 Aug 2026', 'date_to' => '27 Aug 2026'],
                        ['day' => 3, 'location' => 'Sesriem', 'date' => '27 Aug 2026', 'date_to' => '28 Aug 2026'],
                        ['day' => 4, 'location' => 'Etosha National Park', 'date' => '28 Aug 2026', 'date_to' => '29 Aug 2026'],
                    ],
                ]],
            ],
        ]);
    }

    public function test_the_shared_link_unfurls_to_the_plan_rather_than_the_site_card(): void
    {
        $saved = $this->makePlan();

        $html = $this->get(route('trip.show', $saved->share_token))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '<meta property="og:title" content="Trip plan: Dunes, desert and wildlife in twelve days">',
            (string) $html,
        );
        $this->assertStringContainsString('Shared with you on NamibWay', (string) $html);
        $this->assertStringNotContainsString('The smartest way to experience Namibia', (string) $html);
    }

    public function test_the_description_carries_the_length_dates_and_route(): void
    {
        $meta = TripPlanMeta::for($this->makePlan()->plan_json, 'Dunes, desert and wildlife in twelve days');

        $this->assertSame(
            'Shared with you on NamibWay · 4 days, 25 Aug – 29 Aug 2026 · '
            .'Windhoek → Sesriem → Etosha National Park. '
            .'Open the link for the full day-by-day itinerary.',
            $meta['description'],
        );
    }

    public function test_a_long_route_is_abbreviated(): void
    {
        $days = [];

        foreach (['Windhoek', 'Sesriem', 'Swakopmund', 'Twyfelfontein', 'Etosha National Park', 'Otjiwarongo'] as $i => $location) {
            $days[] = ['day' => $i + 1, 'location' => $location];
        }

        $meta = TripPlanMeta::for(['variants' => [['days' => $days]]], 'Grand tour');

        $this->assertStringContainsString(
            'Windhoek → Sesriem → Swakopmund → Twyfelfontein → …',
            $meta['description'],
        );
        $this->assertStringNotContainsString('Otjiwarongo', $meta['description']);
    }

    public function test_a_plan_with_no_days_or_title_still_produces_a_usable_card(): void
    {
        $meta = TripPlanMeta::for(['variants' => []], null);

        $this->assertSame('Namibia trip plan', $meta['title']);
        $this->assertSame(
            'Shared with you on NamibWay. Open the link for the full day-by-day itinerary.',
            $meta['description'],
        );
    }

    public function test_every_other_page_keeps_the_site_card(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('The smartest way to experience Namibia', false);
    }
}
