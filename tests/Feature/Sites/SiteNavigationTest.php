<?php

namespace Tests\Feature\Sites;

use App\Enums\ShopProductStatus;
use App\Models\ShopProduct;
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

    /**
     * On a shop's website the products are the reason the page exists, so the
     * one section selling them cannot be the one section the menu leaves out.
     * It was: `shop` was missing from the menu's list of types, so a retail
     * site offered About and Gallery and no way to reach the shelves.
     */
    public function test_the_shop_is_in_the_menu(): void
    {
        $site = $this->siteWith([
            'about' => ['heading' => 'About us', 'body' => 'A bead workshop.'],
            'shop' => ['heading' => 'Our collection'],
        ]);

        ShopProduct::create([
            'site_id' => $site->id,
            'title' => 'Beaded collar',
            'status' => ShopProductStatus::Published,
            
            'sort' => 0,
        ]);

        // The heading alone proves nothing — the band prints it too. The link
        // to the band's anchor is what only the menu emits.
        $this->get($site->publicUrl())->assertOk()->assertSee('href="#s2"', false);
    }

    /**
     * The band renders only where there is something on the shelves, so the
     * menu must not offer a link to a section the page dropped.
     */
    public function test_an_empty_shop_is_neither_shown_nor_linked(): void
    {
        $site = $this->siteWith([
            'about' => ['heading' => 'About us', 'body' => 'A bead workshop.'],
            'shop' => ['heading' => 'Our collection'],
        ]);

        $this->get($site->publicUrl())->assertOk()
            ->assertDontSee('Our collection')
            ->assertDontSee('href="#s2"', false);
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
        $this->assertStringContainsString('id="nav-panel" hidden', $html);

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
     * The menu is a list of places on the page, and booking is not one of them:
     * it is an action, and where its button goes is placed per screen (see
     * SiteActionButtonsTest). What matters here is that a crowded menu still
     * fits the bar and still says where you are.
     */
    public function test_a_crowded_menu_keeps_its_cap_and_leaves_booking_out_of_it(): void
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

        $html = view('sites.partials.nav', [
            'site' => $site,
            'page' => $page,
            'blocks' => $blocks,
            'hasHero' => true,
        ])->render();

        $links = substr($html, (int) strpos($html, '<nav class="nav__links">'));
        $links = substr($links, 0, (int) strpos($links, '</nav>'));

        $this->assertStringContainsString('Home', $links);
        // Not as a menu item. It is in the bar as the button the scroll brings
        // in — see SiteActionButtonsTest — which is a different thing.
        $this->assertStringNotContainsString('Book now', $links);
        // Five bands plus the one that says where you are, and no more: the bar
        // has a fixed height the hero is pulled up under by exactly that many
        // pixels.
        preg_match_all('/<a href="(#[^"]*)"/', $html, $matches);

        $this->assertCount(6, array_unique($matches[1]), 'the menu is capped at six');
    }

    public function test_a_logo_replaces_the_name_in_the_bar(): void
    {
        $site = $this->siteWith([
            'about' => ['heading' => 'About us', 'body' => 'A lodge on the plains.'],
        ]);

        // Matched on the attribute, not the bare class name: the stylesheet is
        // inline, so `nav__logo` is on every page whether or not there is one.
        $this->get($site->publicUrl())->assertDontSee('class="nav__logo"', false);

        $site->update(['logo_key' => 'sites/logos/mark.png']);

        $this->get($site->publicUrl())
            ->assertSee('class="nav__logo"', false)
            // e(), because the factory's name is a real company name and some
            // of them have an apostrophe in — "O'Hara-Cormier" is escaped in
            // the attribute and was not in the expectation, which failed this
            // test on the runs where faker happened to pick one.
            ->assertSee('alt="'.e($site->name).'"', false);
    }

    /**
     * The hero is pulled up under the bar by exactly -64px, so a bar that can
     * grow leaves a strip of page background above the photograph. Measured at
     * 834px it was 90px tall and the strip was 26px. These three rules are what
     * hold the height, so they are asserted rather than trusted.
     */
    public function test_the_bar_cannot_grow_past_the_height_the_hero_cancels(): void
    {
        $site = $this->siteWith([
            'hero' => ['headline' => 'Stay a while'],
            'about' => ['heading' => 'About us', 'body' => 'Words.'],
        ]);

        $css = $this->get($site->publicUrl())->assertOk()->getContent();

        $this->assertIsString($css);
        // The bar's height is fixed via var(--nav-height) (default 64 px). The
        // hero is pulled up under it by calc(-1 * var(--nav-height)), so any
        // configured height keeps the arithmetic correct.
        // overflow:visible rather than hidden: the name is constrained by
        // -webkit-line-clamp and the logo by its own height rule, so no clipping
        // is needed — and hidden caused a two-step logo transition by snapping
        // the clip boundary mid-animation as .is-scrolled was added.
        $this->assertStringContainsString('height: var(--nav-height); overflow: visible;', $css);
        $this->assertStringContainsString('.hero { position: relative; margin-top: calc(-1 * var(--nav-height));', $css);
        // A menu item breaking across two lines was half of what made it grow.
        // The other half was the name, which is allowed to wrap now — but only
        // inside the bar, clamped to the two lines its padding leaves room for.
        $this->assertMatchesRegularExpression('/\.nav__links a \{[^}]*white-space: nowrap/s', $css);
        $this->assertMatchesRegularExpression('/\.nav__name \{[^}]*-webkit-line-clamp: 2/s', $css);
    }

    public function test_a_long_name_is_set_smaller_and_never_cut(): void
    {
        $short = $this->siteWith(['about' => ['heading' => 'About us', 'body' => 'Words.']]);
        $short->update(['name' => 'Dune Edge']);

        // Mobile first: the base custom property is the phone size and the
        // wide-screen one is raised in a media query.
        $this->get($short->publicUrl())
            ->assertSee('--brand-size: 20px', false)
            ->assertSee('@media (min-width: 640px) { :root { --brand-size: 22px; } }', false);

        $long = Site::factory()->create(['name' => 'Ongombo West #56 Hunting Safari', 'slug' => 'long-name']);
        SitePage::factory()->create(['site_id' => $long->id, 'title' => $long->name]);
        SiteBlock::create([
            'site_page_id' => $long->pages()->first()->id,
            'type' => 'about',
            'data' => ['heading' => 'About us', 'body' => 'Words.'],
            'sort' => 0,
        ]);

        $this->get($long->publicUrl())
            ->assertSee('--brand-size: 17px', false)
            ->assertSee('@media (min-width: 640px) { :root { --brand-size: 20px; } }', false);
    }

    /**
     * The regression this replaced: the name was one line with an ellipsis,
     * which on a phone left about 250px for a name needing 350 — and
     * `text-overflow` does nothing on a flex container, so it was severed
     * mid-word with nothing to say so. "…Hunting Safari" became "…Hunting Safa".
     */
    public function test_the_name_wraps_inside_the_bar_rather_than_being_cut(): void
    {
        $site = $this->siteWith([
            'hero' => ['headline' => 'Stay a while'],
            'about' => ['heading' => 'About us', 'body' => 'Words.'],
        ]);

        $css = $this->get($site->publicUrl())->assertOk()->getContent();

        $this->assertIsString($css);
        // Two lines, clamped — not one line with an ellipsis.
        $this->assertMatchesRegularExpression('/\.nav__name \{[^}]*white-space: normal/s', $css);
        $this->assertMatchesRegularExpression('/\.nav__name \{[^}]*-webkit-line-clamp: 2/s', $css);
        // On the declaration, not the word: the rule's own comment explains
        // why the ellipsis went, and that comment is served with the page.
        // Scoped to the nav name rule — other elements (shop breadcrumb etc.)
        // may legitimately use text-overflow for their own content.
        $this->assertDoesNotMatchRegularExpression('/\.nav__name \{[^}]*text-overflow:/s', $css);
        // And the room for them: the bar's outside height is untouched, its
        // padding is what made space. overflow:visible — see test above.
        $this->assertStringContainsString('height: var(--nav-height); overflow: visible; padding: var(--s2) var(--s4);', $css);
    }

    /**
     * The panel is a cream sheet directly under the bar, so the bar has to stop
     * being white-on-a-photograph at the same moment — and without a fade, or
     * the name is white on cream for as long as it takes to notice.
     */
    public function test_the_open_menu_takes_the_bar_off_its_photograph_colours(): void
    {
        $site = $this->siteWith([
            'hero' => ['headline' => 'Stay a while'],
            'about' => ['heading' => 'About us', 'body' => 'Words.'],
        ]);

        $html = $this->get($site->publicUrl())->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('.nav.is-scrolled, .nav.is-open { background: var(--salt)', $html);
        $this->assertStringContainsString('.nav.is-open .nav__name { color: var(--ink); }', $html);
        $this->assertStringContainsString('.nav.is-open, .nav.is-open .nav__name, .nav.is-open .nav__burger span { transition: none; }', $html);
        // And the script that puts the class there in the first place.
        $this->assertStringContainsString('classList.toggle(\'is-open\', open)', $html);
    }

    public function test_a_site_with_nothing_to_link_to_has_no_burger(): void
    {
        $site = $this->siteWith([
            'hero' => ['headline' => 'Dune Edge Lodge'],
        ]);

        // Again the attribute rather than the name: the page's own script looks
        // the button up by id, so the string is present even where the button
        // is not.
        $this->get($site->publicUrl())->assertDontSee('id="nav-burger"', false);
    }
}
