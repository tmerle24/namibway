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

    public function test_a_site_with_nothing_to_link_to_has_no_burger(): void
    {
        $site = $this->siteWith([
            'hero' => ['headline' => 'Dune Edge Lodge'],
        ]);

        $this->get($site->publicUrl())->assertDontSee('nav-burger');
    }
}
