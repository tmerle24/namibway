<?php

namespace Tests\Feature\Sites;

use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\Blocks\EnquiryBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Book, call, WhatsApp — the three things a visitor is ever asked to do.
 *
 * What has to hold: the booking button is not a menu item hidden behind a
 * burger on the surface most visitors arrive on; the phone-sized layout offers
 * all three where a thumb already is; and each one is switchable off, on by
 * default, without taking the business's contact details off the page with it.
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
     * where we hold a listing — an `Inquiry` belongs to one — so this is what a
     * site with a real primary action looks like.
     */
    private function lodgeTakingEnquiries(): Site
    {
        $listing = Listing::factory()->create(['accepts_inquiries' => true]);

        $site = $this->lodge(['source_listing_id' => $listing->id]);

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

    /**
     * Just the strip at the foot of the page — it holds anchors and nothing
     * else, so the first closing div is its own.
     */
    private function actionBar(string $markup): string
    {
        $start = strpos($markup, '<div class="bar">');

        return $start === false ? '' : substr($markup, $start, (int) strpos($markup, '</div>', $start) - $start);
    }

    /**
     * The regression this was built for: booking was the last link in the menu,
     * so on a phone the one thing the site exists to do was behind a burger.
     */
    public function test_the_booking_button_is_a_button_in_the_bar_not_a_menu_item(): void
    {
        $markup = $this->fetch($this->lodge());

        $this->assertStringContainsString('class="btn nav__cta"', $markup);

        // In the bar itself, ahead of the burger — not inside the panel the
        // burger opens, which is where it used to be hiding.
        $panel = substr($markup, (int) strpos($markup, 'id="nav-panel"'));

        $this->assertMatchesRegularExpression('/nav__cta.*?<button class="nav__burger"/s', $markup);
        $this->assertStringNotContainsString('nav__cta', $panel);
    }

    /**
     * Most sites have no booking block — it renders only where the property has
     * sellable inventory with us. The button still has somewhere to go.
     */
    public function test_the_primary_action_falls_back_from_booking_to_the_contact_section(): void
    {
        $site = $this->lodge();

        // Blocks: about is s1, contact is s2. No booking, no enquiry.
        $this->get($site->publicUrl())->assertOk()->assertSee('href="#s2"', false);
    }

    public function test_the_hero_offers_the_action_and_whatsapp_without_anybody_typing_one_in(): void
    {
        $markup = $this->fetch($this->lodge());

        // Generation leaves cta_label/cta_href empty, so before this the first
        // screen had no button at all.
        $this->assertMatchesRegularExpression('/hero__cta.*?class="btn"/s', $markup);
        $this->assertStringContainsString('https://wa.me/264810000000', $markup);
        $this->assertStringContainsString('btn btn--light', $markup);
    }

    public function test_a_hero_cta_the_owner_typed_wins_over_the_derived_one(): void
    {
        $site = $this->siteWith([
            'hero' => [
                'headline' => 'Where the desert begins',
                'cta_label' => 'See the rooms',
                'cta_href' => 'https://example.com/rooms',
            ],
            'about' => ['heading' => 'About us', 'body' => 'Words.'],
            'contact' => ['heading' => 'Find us'],
        ]);

        $this->get($site->publicUrl())
            ->assertOk()
            ->assertSee('See the rooms')
            ->assertSee('https://example.com/rooms', false);
    }

    public function test_the_action_bar_carries_all_three_and_makes_room_for_itself(): void
    {
        $markup = $this->fetch($this->lodgeTakingEnquiries());
        $bar = $this->actionBar($markup);

        $this->assertStringContainsString('href="tel:+26464400000"', $bar);
        $this->assertStringContainsString('https://wa.me/264810000000', $bar);
        $this->assertStringContainsString('bar__item--primary', $bar);
        // Fixed to the bottom of the screen, so the last thing on the page
        // needs the height taking out of the flow — the spacer, not padding on
        // the body, so the two can never exist without each other.
        $this->assertStringContainsString('<div class="bar__spacer" aria-hidden="true"></div>', $markup);
    }

    /**
     * A third button meaning "the same two things, further down the page" is
     * noise on the surface with least room for it. The bar at the top and the
     * hero still offer it — there it is the only action on the screen.
     */
    public function test_a_contact_only_primary_stays_out_of_the_strip_beside_call_and_whatsapp(): void
    {
        $markup = $this->fetch($this->lodge());

        $this->assertStringNotContainsString('bar__item--primary', $this->actionBar($markup));
        $this->assertStringContainsString('class="btn nav__cta"', $markup);
        $this->assertMatchesRegularExpression('/hero__cta.*?>Contact</s', $markup);
    }

    public function test_a_contact_only_primary_is_in_the_strip_when_it_is_the_only_action(): void
    {
        $markup = $this->fetch($this->lodge(['contact_phone' => null, 'whatsapp' => null]));

        $this->assertStringContainsString('bar__item--primary', $this->actionBar($markup));
    }

    public function test_a_site_with_no_number_and_nothing_to_book_gets_no_action_bar(): void
    {
        $site = $this->siteWith([
            'hero' => ['headline' => 'Dune Edge Lodge'],
        ], ['contact_phone' => null, 'whatsapp' => null]);

        $this->assertStringNotContainsString('<div class="bar">', $this->fetch($site));
    }

    public function test_every_button_is_on_by_default(): void
    {
        $site = Site::factory()->create();

        $this->assertTrue($site->show_book_button);
        $this->assertTrue($site->show_call_button);
        $this->assertTrue($site->show_whatsapp_button);
    }

    public function test_switching_the_call_button_off_keeps_the_number_on_the_page(): void
    {
        $markup = $this->fetch($this->lodge(['show_call_button' => false]));

        $this->assertStringNotContainsString('href="tel:', $this->actionBar($markup));
        // The contact section still lists it, and still dials. Switching a
        // button off is a decision about buttons, not about publishing a
        // number.
        $this->assertStringContainsString('+264 64 400 000', $markup);
        $this->assertStringContainsString('href="tel:+26464400000"', $markup);
    }

    public function test_switching_the_whatsapp_button_off_takes_it_off_the_hero_and_the_contact_section(): void
    {
        $markup = $this->fetch($this->lodge(['show_whatsapp_button' => false]));

        $this->assertStringNotContainsString('btn btn--light', $markup);
        $this->assertStringNotContainsString('Message us on WhatsApp', $markup);
        $this->assertStringNotContainsString('wa.me', $this->actionBar($markup));
        // The number itself stays, as a contact detail.
        $this->assertStringContainsString('+264 81 000 0000', $markup);
    }

    public function test_switching_the_booking_button_off_removes_it_everywhere_at_once(): void
    {
        $markup = $this->fetch($this->lodge(['show_book_button' => false]));

        $this->assertStringNotContainsString('nav__cta', $markup);
        $this->assertStringNotContainsString('bar__item--primary', $markup);
        // The other two are untouched, and the strip is still there for them.
        $this->assertStringContainsString('<div class="bar">', $markup);
    }

    public function test_a_booking_heading_too_long_for_a_button_falls_back_to_a_short_label(): void
    {
        $site = $this->siteWith([
            'hero' => ['headline' => 'Where the desert begins'],
            'enquiry' => ['heading' => 'Request availability', 'mode' => 'stay'],
        ]);

        // The enquiry block renders only for a site built from a listing, so
        // the label is asserted on the partial rather than through the page.
        $page = $site->pages()->first();

        $html = view('sites.partials.nav', [
            'site' => $site,
            'page' => $page,
            'blocks' => $page->renderableBlocks()->get(),
            'hasHero' => true,
        ])->render();

        // The section's own heading stays what the owner wrote — it is the
        // button that cannot be a sentence.
        $this->assertStringContainsString('class="btn nav__cta" href="#s1">Enquire</a>', $html);
        $this->assertStringContainsString('>Request availability</a>', $html);
    }
}
