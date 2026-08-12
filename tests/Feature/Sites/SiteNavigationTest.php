<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bar at the top, and the same links behind a burger on a phone.
 *
 * Most visitors arrive on a phone, so the narrow layout is the real one. What
 * has to hold: the burger's panel offers exactly what the bar offers — one
 * array rendered twice, never two lists that can drift — and a page with
 * JavaScript switched off shows neither an open panel nor a button that cannot
 * close it.
 */
class SiteNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function siteWith(array $types): Site
    {
        $site = Site::factory()->create();
        $page = SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        $sort = 0;

        foreach ($types as $type => $data) {
            SiteBlock::create([
                'site_page_id' => $page->id,
                'type' => $type,
                'data' => $data,
                'sort' => $sort++,
            ]);
        }

        return $site;
    }

    public function test_highlights_is_in_the_menu(): void
    {
        $site = $this->siteWith([
            'highlights' => ['heading' => 'What we offer', 'items' => [['title' => 'Dune walks', 'text' => null]]],
        ]);

        $this->get($site->publicUrl())->assertSee('What we offer');
    }

    public function test_the_burger_panel_carries_the_same_links_as_the_bar(): void
    {
        $site = $this->siteWith([
            'highlights' => ['heading' => 'What we offer', 'items' => [['title' => 'Dune walks', 'text' => null]]],
            'about' => ['heading' => 'About us', 'body' => 'A lodge on the plains.'],
        ]);

        $html = $this->get($site->publicUrl())->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('id="nav-burger"', $html);
        $this->assertStringContainsString('id="nav-panel"', $html);

        // Each anchor appears twice: once in the bar, once in the panel.
        foreach (['#s1', '#s2'] as $anchor) {
            $this->assertSame(2, substr_count($html, 'href="'.$anchor.'"'), $anchor.' should be in both');
        }
    }

    /**
     * Both arrive hidden and the script unhides the button. A page served to a
     * browser with scripting off must not show a control that cannot work.
     */
    public function test_the_burger_and_its_panel_start_hidden(): void
    {
        $site = $this->siteWith([
            'about' => ['heading' => 'About us', 'body' => 'A lodge on the plains.'],
        ]);

        $this->get($site->publicUrl())
            ->assertSee('aria-label="Menu" hidden', false)
            ->assertSee('id="nav-panel" hidden', false);
    }

    public function test_home_is_the_first_item_and_points_at_the_top_of_the_page(): void
    {
        $site = $this->siteWith([
            'about' => ['heading' => 'About us', 'body' => 'A lodge on the plains.'],
        ]);

        $this->get($site->publicUrl())
            ->assertSee('Home')
            ->assertSee('<body id="top">', false);
    }

    /**
     * Booking is the thing the site exists to do, so it must not be the item
     * that falls off the end when a business has a lot to say.
     */
    public function test_booking_keeps_its_place_in_a_crowded_menu(): void
    {
        $site = Site::factory()->create();
        $page = SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        $crowd = [
            'about' => ['heading' => 'About us', 'body' => 'Words.'],
            'highlights' => ['heading' => 'What we offer', 'items' => [['title' => 'Dune walks', 'text' => null]]],
            'opening_hours' => ['heading' => 'Hours', 'days' => [['day' => 'Monday', 'hours' => '09:00–17:00']]],
            'price_list' => ['heading' => 'Prices', 'items' => [['name' => 'Night', 'value' => 'N$ 1 200', 'note' => null]]],
            'rich_text' => ['heading' => 'More', 'body' => 'Words.'],
            'enquiry' => ['heading' => 'Request availability', 'mode' => 'stay'],
        ];

        $sort = 0;

        foreach ($crowd as $type => $data) {
            SiteBlock::create(['site_page_id' => $page->id, 'type' => $type, 'data' => $data, 'sort' => $sort++]);
        }

        $blocks = $page->renderableBlocks()->get()->push(
            new SiteBlock(['type' => 'booking', 'data' => ['heading' => 'Book now']])
        );

        $html = view('sites.partials.nav', ['site' => $site, 'blocks' => $blocks, 'hasHero' => true])->render();

        $this->assertStringContainsString('Book now', $html);
        $this->assertStringContainsString('Home', $html);
    }

    public function test_a_logo_replaces_the_name_in_the_bar(): void
    {
        $site = $this->siteWith([
            'about' => ['heading' => 'About us', 'body' => 'A lodge on the plains.'],
        ]);

        $this->get($site->publicUrl())->assertDontSee('nav__logo');

        $site->update(['logo_key' => 'sites/logos/mark.png']);

        $this->get($site->publicUrl())
            ->assertSee('nav__logo', false)
            ->assertSee('alt="'.$site->name.'"', false);
    }

    public function test_a_site_with_nothing_to_link_to_has_no_burger(): void
    {
        $site = $this->siteWith([
            'hero' => ['headline' => 'Dune Edge Lodge'],
        ]);

        $this->get($site->publicUrl())->assertDontSee('nav-burger');
    }
}
