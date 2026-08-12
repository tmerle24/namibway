<?php

namespace Tests\Feature\Sites;

use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\ActionButtons;
use App\Sites\Blocks\EnquiryBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The action buttons: enquire, WhatsApp, call, and one the business writes
 * itself — each placed in the menu bar, the opening screen or the strip at the
 * foot of the screen, separately for a phone and for a desktop.
 *
 * What has to hold: the defaults are the ones that were asked for, a placement
 * for something that does not exist never renders a broken button, a button
 * meant for one screen carries the class that hides it on the other, and the
 * strip makes room for itself exactly where it is shown.
 */
class SiteActionButtonsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, array<string, mixed>>  $types
     * @param  array<string, mixed>  $attributes
     */
    private function siteWith(array $types, array $attributes = []): Site
    {
        $site = Site::factory()->create($attributes + [
            'contact_phone' => '+264 64 400 000',
            'whatsapp' => '+264 81 000 0000',
        ]);

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

    /**
     * Hero, about, contact — the shape of nearly every generated site: no
     * booking band (that needs sellable inventory) and no enquiry form (that
     * needs a listing).
     */
    private function lodge(array $attributes = []): Site
    {
        return $this->siteWith([
            'hero' => ['headline' => 'Where the desert begins'],
            'about' => ['heading' => 'About us', 'body' => 'A lodge on the plains.'],
            'contact' => ['heading' => 'Find us', 'intro' => null],
        ], $attributes);
    }

    /**
     * The same lodge with somewhere for an enquiry to go. The form renders only
     * where we hold a listing — an `Inquiry` belongs to one.
     */
    private function lodgeTakingEnquiries(array $attributes = []): Site
    {
        $listing = Listing::factory()->create(['accepts_inquiries' => true]);

        $site = $this->lodge($attributes + ['source_listing_id' => $listing->id]);

        SiteBlock::create([
            'site_page_id' => (int) $site->pages()->value('id'),
            'type' => 'enquiry',
            'data' => ['heading' => 'Ask us', 'mode' => EnquiryBlock::MODE_STAY],
            'sort' => 9,
        ]);

        return $site;
    }

    /**
     * The page's markup without its inline stylesheet and script.
     *
     * Both are inlined into every page, so every class name in the design is
     * present in the document whether or not anything uses it — asserting that
     * a page does not contain "nav__cta" against the whole response only ever
     * proves the stylesheet is missing.
     */
    private function markup(string $html): string
    {
        return (string) preg_replace('#<(style|script)\b[^>]*>.*?</\1>#s', '', $html);
    }

    private function fetch(Site $site): string
    {
        $html = $this->get($site->publicUrl())->assertOk()->getContent();

        $this->assertIsString($html);

        return $this->markup($html);
    }

    /** Just the strip at the foot of the page — it holds anchors and nothing else. */
    private function actionBar(string $markup): string
    {
        $start = strpos($markup, '<div class="bar ');

        return $start === false ? '' : substr($markup, $start, (int) strpos($markup, '</div>', $start) - $start);
    }

    private function hero(string $markup): string
    {
        $start = strpos($markup, 'hero__cta');

        return $start === false ? '' : substr($markup, $start, (int) strpos($markup, '</div>', $start) - $start);
    }

    /**
     * The defaults, in one test, because they are the product decision this
     * whole thing exists to express: enquiry under the headline on a desktop
     * and at the foot of the screen on a phone, WhatsApp and the telephone at
     * the foot of a phone screen, the business's own button on the opening
     * screen everywhere, and nothing at all in the menu.
     */
    public function test_the_defaults_are_the_ones_that_were_asked_for(): void
    {
        $buttons = ActionButtons::for(Site::factory()->make());

        $this->assertSame('desktop', $buttons->visibility('enquiry', 'hero'));
        $this->assertSame('phone', $buttons->visibility('enquiry', 'footer'));
        $this->assertNull($buttons->visibility('enquiry', 'menu'));

        $this->assertSame('phone', $buttons->visibility('whatsapp', 'footer'));
        $this->assertNull($buttons->visibility('whatsapp', 'hero'));

        $this->assertSame('phone', $buttons->visibility('call', 'footer'));

        $this->assertSame('both', $buttons->visibility('custom', 'hero'));
        $this->assertNull($buttons->visibility('custom', 'footer'));

        foreach (ActionButtons::ACTIONS as $action) {
            $this->assertNull($buttons->visibility($action, 'menu'), $action.' should not be in the menu');
        }
    }

    public function test_the_menu_carries_no_standing_button_until_somebody_asks_for_one(): void
    {
        $markup = $this->fetch($this->lodgeTakingEnquiries());
        $header = substr($markup, 0, (int) strpos($markup, '</header>'));

        // The only button in the bar is the one the scroll brings in, and it
        // arrives hidden.
        $this->assertSame(1, substr_count($header, 'class="btn nav__cta'));
        $this->assertStringContainsString('nav__cta--scroll', $header);
    }

    /**
     * The opening screen carries the enquiry button and then scrolls away with
     * it. From that moment the bar has it — swapped for the menu item that says
     * the same thing on a wide screen, and simply beside the burger where there
     * is no menu to swap.
     */
    public function test_the_bar_picks_up_the_enquiry_button_once_the_page_has_scrolled(): void
    {
        $html = $this->get($this->lodgeTakingEnquiries()->publicUrl())->assertOk()->getContent();

        $this->assertIsString($html);
        $markup = $this->markup($html);

        // The button, and the menu item it stands in for, both present and
        // marked — the stylesheet swaps them, so both have to be there.
        $this->assertMatchesRegularExpression('/nav__cta nav__cta--scroll"\s+href="#s3">Ask us/', $markup);
        $this->assertStringContainsString('class="nav__link--action"', $markup);

        // Hidden until the bar has the class the scroll puts on it, and only
        // wide enough for a button beside the burger.
        $this->assertStringContainsString('.nav .nav__cta--scroll { display: none; }', $html);
        $this->assertStringContainsString('.nav.is-scrolled .nav__cta--scroll { display: inline-block; }', $html);
        $this->assertStringContainsString('.nav.is-scrolled .nav__links .nav__link--action { display: none; }', $html);
        // And the class itself is put on by every page, not only the ones with
        // a hero — a page with no hero has an opening screen that scrolls away
        // just the same.
        $this->assertMatchesRegularExpression('/if \(nav\) \{\s*var onScroll/', $html);
    }

    /**
     * "Request availability" after "Get in touch": the one item in the menu
     * that is an action rather than a place goes at the end of the row, which
     * is where it turns into a button.
     */
    public function test_the_enquiry_item_is_the_last_thing_in_the_menu(): void
    {
        $markup = $this->fetch($this->lodgeTakingEnquiries());
        $links = substr($markup, (int) strpos($markup, '<nav class="nav__links">'));
        $links = substr($links, 0, (int) strpos($links, '</nav>'));

        preg_match_all('/>([^<>]+)<\/a>/', $links, $matches);
        $labels = array_map(trim(...), $matches[1]);

        $this->assertSame('Ask us', end($labels));
        $this->assertContains('Find us', $labels);
    }

    /**
     * A business that asked for the button in the menu gets it from the first
     * pixel, and not twice.
     */
    public function test_a_placed_menu_button_replaces_the_one_the_scroll_would_bring(): void
    {
        $site = $this->lodgeTakingEnquiries();
        $site->update(['action_buttons' => ['enquiry' => ['places' => ['menu.desktop']]]]);

        $header = substr($this->fetch($site), 0, (int) strpos($this->fetch($site), '</header>'));

        $this->assertStringNotContainsString('nav__cta--scroll', $header);
        $this->assertSame(1, substr_count($header, 'class="btn nav__cta'));
    }

    public function test_a_button_placed_in_the_menu_appears_there_on_the_screen_it_was_placed_on(): void
    {
        $site = $this->lodgeTakingEnquiries();
        $site->update(['action_buttons' => ['enquiry' => ['places' => ['menu.desktop']]]]);

        $markup = $this->fetch($site);
        $header = substr($markup, 0, (int) strpos($markup, '</header>'));

        $this->assertStringContainsString('nav__cta', $header);
        // The class that hides it on a phone — the page is rendered once and
        // the stylesheet decides, since the server cannot know the screen.
        $this->assertMatchesRegularExpression('/nav__cta[^"]*at-desktop/', $header);
    }

    public function test_the_opening_screen_leads_with_enquire_on_a_desktop_and_the_custom_button_on_both(): void
    {
        $hero = $this->hero($this->fetch($this->lodgeTakingEnquiries()));

        // Enquire: desktop only, and the filled button wherever it appears.
        $this->assertMatchesRegularExpression('/class="btn\s+at-desktop"[^>]*>\s*Ask us/s', $hero);
        // The business's own button, defaulting to the About band, on both.
        $this->assertMatchesRegularExpression('/btn--light[^"]*"\s+href="#s1"/', $hero);
        $this->assertStringContainsString('About us', $hero);
        // WhatsApp is not on the opening screen by default.
        $this->assertStringNotContainsString('wa.me', $hero);
    }

    public function test_the_strip_carries_call_whatsapp_and_enquire_on_a_phone(): void
    {
        $markup = $this->fetch($this->lodgeTakingEnquiries());
        $bar = $this->actionBar($markup);

        $this->assertStringContainsString('href="tel:+26464400000"', $bar);
        $this->assertStringContainsString('https://wa.me/264810000000', $bar);
        $this->assertStringContainsString('bar__item--primary', $bar);

        // The strip itself is a phone thing by default, so both it and the
        // space it takes out of the flow are hidden on a desktop.
        $this->assertStringContainsString('<div class="bar at-phone">', $markup);
        $this->assertStringContainsString('<div class="bar__spacer at-phone" aria-hidden="true"></div>', $markup);
    }

    public function test_a_strip_placed_on_a_desktop_too_is_shown_on_both(): void
    {
        $site = $this->lodgeTakingEnquiries();
        $site->update(['action_buttons' => [
            'call' => ['places' => ['footer.phone', 'footer.desktop']],
            'enquiry' => ['places' => ['footer.phone']],
        ]]);

        $markup = $this->fetch($site);

        // The strip is on every screen because one of its buttons is; the
        // buttons themselves still say which screen they are for.
        $this->assertStringContainsString('<div class="bar ">', $markup);
        $this->assertMatchesRegularExpression('/bar__item[^"]*"\s+href="tel:/', $markup);
        $this->assertMatchesRegularExpression('/bar__item--primary\s+at-phone/', $markup);
    }

    public function test_a_site_that_placed_nothing_at_the_foot_gets_no_strip(): void
    {
        $site = $this->lodge();
        $site->update(['action_buttons' => [
            'enquiry' => ['places' => ['hero.desktop']],
            'whatsapp' => ['places' => []],
            'call' => ['places' => []],
            'custom' => ['places' => ['hero.phone', 'hero.desktop']],
        ]]);

        $markup = $this->fetch($site);

        $this->assertStringNotContainsString('<div class="bar ', $markup);
        $this->assertStringNotContainsString('bar__spacer', $markup);
    }

    /**
     * A button that opens WhatsApp on nobody is worse than no button, and that
     * must not depend on somebody remembering to switch it off.
     */
    public function test_a_placement_for_something_that_does_not_exist_renders_nothing(): void
    {
        $site = $this->lodge(['whatsapp' => null, 'contact_phone' => null]);
        $site->update(['action_buttons' => [
            'whatsapp' => ['places' => ['hero.phone', 'hero.desktop', 'footer.phone']],
            'call' => ['places' => ['footer.phone']],
        ]]);

        $markup = $this->fetch($site);

        $this->assertStringNotContainsString('wa.me', $markup);
        $this->assertStringNotContainsString('href="tel:', $markup);
    }

    public function test_the_enquiry_button_falls_back_to_the_contact_band_and_is_labelled_for_a_button(): void
    {
        // No booking band and no enquiry form: the contact band is what is
        // left, and "Find us" on a button reads as directions.
        $hero = $this->hero($this->fetch($this->lodge()));

        $this->assertStringNotContainsString('Find us', $hero);
    }

    public function test_a_label_the_business_wrote_wins_over_the_derived_one(): void
    {
        $site = $this->lodgeTakingEnquiries();
        $site->update(['action_buttons' => [
            'enquiry' => ['places' => ['hero.phone', 'hero.desktop'], 'label' => 'Book a hunt'],
        ]]);

        $this->assertStringContainsString('Book a hunt', $this->hero($this->fetch($site)));
    }

    public function test_the_custom_button_takes_a_label_and_a_link_of_its_own(): void
    {
        $site = $this->lodge();
        $site->update(['action_buttons' => [
            'custom' => ['places' => ['hero.phone', 'hero.desktop'], 'label' => 'The lodge', 'href' => 'https://example.com/lodge'],
        ]]);

        $hero = $this->hero($this->fetch($site));

        $this->assertStringContainsString('The lodge', $hero);
        $this->assertStringContainsString('https://example.com/lodge', $hero);
        // It leaves the site, so it opens in its own tab and does not hand the
        // referrer a window handle.
        $this->assertStringContainsString('rel="noopener"', $hero);
    }

    /**
     * `javascript:` and `data:` both run in the page's own origin, on a domain
     * we host under our own certificate. The custom button is the one field
     * where a typed string could become code.
     */
    public function test_a_custom_link_that_could_run_code_is_refused(): void
    {
        $site = $this->lodge();
        $site->update(['action_buttons' => [
            'custom' => ['places' => ['hero.desktop'], 'href' => 'javascript:alert(1)'],
        ]]);

        $markup = $this->fetch($site);

        $this->assertStringNotContainsString('javascript:', $markup);
    }

    public function test_a_site_with_no_about_band_and_no_link_has_no_custom_button(): void
    {
        $site = $this->siteWith([
            'hero' => ['headline' => 'Where the desert begins'],
            'contact' => ['heading' => 'Find us'],
        ]);

        $this->assertStringNotContainsString('About us', $this->fetch($site));
    }

    /** Unknown places are dropped rather than carried into the renderer. */
    public function test_a_placement_that_is_not_a_place_is_thrown_away(): void
    {
        $config = ActionButtons::normalise([
            'enquiry' => ['places' => ['footer.tablet', 'hero.desktop', 'nonsense']],
            'nonsense' => ['places' => ['hero.phone']],
        ]);

        $this->assertSame(['hero.desktop'], $config['enquiry']['places']);
        $this->assertArrayNotHasKey('nonsense', $config);
        // An action the payload does not mention keeps its default rather than
        // silently losing its buttons.
        $this->assertSame(['footer.phone'], $config['call']['places']);
    }

    public function test_the_headline_breaks_where_the_business_broke_it(): void
    {
        $site = $this->siteWith([
            'hero' => ['headline' => "Worth getting\nup for"],
        ]);

        $markup = $this->fetch($site);

        $this->assertStringContainsString("Worth getting<br />\nup for", $markup);
    }

    public function test_the_bar_shows_the_short_name_where_there_is_one_and_breaks_where_it_says(): void
    {
        $site = $this->lodge();
        $site->update([
            'name' => 'Ongombo West #56 Hunting Safari',
            'brand_name' => "Ongombo West #56\nHunting Safari",
        ]);
        $site->pages()->update(['title' => null]);

        $markup = $this->fetch($site);

        $this->assertStringContainsString("Ongombo West #56<br />\nHunting Safari", $markup);
        // The full name is still what the page is called: a line break belongs
        // in the bar and nowhere else.
        $this->assertStringContainsString('<title>Ongombo West #56 Hunting Safari</title>', $markup);
    }

    /**
     * The size is worked out from the longest line, not the whole string —
     * which is the point of being able to break it by hand.
     */
    public function test_a_hand_broken_name_is_set_at_the_size_its_longest_line_needs(): void
    {
        $site = $this->lodge();
        $site->update(['name' => 'Ongombo West #56 Hunting Safari']);

        $this->get($site->publicUrl())->assertSee('--brand-size: 17px', false);

        $site->update(['brand_name' => "Ongombo West #56\nHunting Safari"]);

        $this->get($site->publicUrl())->assertSee('--brand-size: 20px', false);
    }

    /**
     * And held down to what two lines can be. The bar leaves a name 48px, which
     * at a line height of 1.2 is 20px a line — a hand-broken name is short
     * enough for the steps above to ask for 22px, and two lines of that are
     * 53px, which the bar clips. Found by measuring, the moment the first
     * hand-broken name went in.
     */
    public function test_a_hand_broken_name_is_never_set_too_large_for_two_lines(): void
    {
        $site = $this->lodge();
        $site->update(['name' => 'Dune Edge', 'brand_name' => null]);

        $this->get($site->publicUrl())->assertSee('--brand-size: 22px; } }', false);

        $site->update(['brand_name' => "Dune\nEdge"]);

        $this->get($site->publicUrl())->assertSee('--brand-size: 20px; } }', false);
    }
}
