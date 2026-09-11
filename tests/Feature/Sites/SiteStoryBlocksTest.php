<?php

namespace Tests\Feature\Sites;

use App\Filament\Support\EditSiteImagesAction;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SiteImage;
use App\Models\SitePage;
use App\Sites\Blocks\GalleryBlock;
use App\Sites\Publishing\PublishGate;
use App\Sites\Rendering\HtmlWhitespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The bands added for the first tour operator (2026-09-10): offers, photo
 * band, video, team, testimonials, FAQ — and the gallery without its cap.
 */
class SiteStoryBlocksTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_cards_render_and_each_leads_to_the_form_with_its_title(): void
    {
        $site = $this->site();
        $photo = $this->image($site);

        $this->block($site, 'offers', [
            'heading' => 'Tours & safaris',
            'items' => [[
                'title' => 'Etosha full day',
                'text' => "Sunrise at the gate.\n<b>Lunch</b> at Okaukuejo.",
                'duration' => 'Full day',
                'price' => 'from N$ 1 450 pp',
                'image_id' => $photo->id,
            ]],
        ], 0);
        $this->block($site, 'enquiry', ['heading' => 'Ask us'], 1);

        $html = $this->page($site);

        $this->assertStringContainsString('Etosha full day', $html);
        $this->assertStringContainsString('from N$ 1 450 pp', $html);
        // Plain text, line breaks kept, markup escaped.
        $this->assertStringContainsString('Sunrise at the gate.<br />', $html);
        $this->assertStringContainsString('&lt;b&gt;Lunch&lt;/b&gt;', $html);
        // The picture resolved from inside the item.
        $this->assertStringContainsString(basename($photo->key), $html);
        // Enquiry is band 02 → anchor s2.
        $this->assertStringContainsString('href="#s2" data-enquire="Etosha full day"', $html);
    }

    public function test_the_gallery_shows_nine_and_keeps_the_rest_behind_show_all(): void
    {
        $site = $this->site();
        $ids = collect(range(1, 14))->map(fn () => $this->image($site)->id)->all();

        $this->block($site, 'gallery', ['image_ids' => $ids]);

        $html = $this->page($site);

        $this->assertSame(14 - GalleryBlock::VISIBLE, substr_count($html, '<figure class="reveal is-more">'));
        $this->assertStringContainsString('Show all 14 photos', $html);
        $this->assertStringContainsString('class="grid-photos grid-photos--feature"', $html);
    }

    public function test_a_small_gallery_has_no_show_all(): void
    {
        $site = $this->site();
        $ids = collect(range(1, 4))->map(fn () => $this->image($site)->id)->all();

        $this->block($site, 'gallery', ['image_ids' => $ids]);

        $html = $this->page($site);

        $this->assertStringNotContainsString('data-gallery-more="', $html);
        $this->assertStringNotContainsString('class="grid-photos grid-photos--feature"', $html);
    }

    public function test_the_gallery_takes_more_than_twelve(): void
    {
        $site = $this->site();

        $block = $this->block($site, 'gallery', ['image_ids' => range(1, GalleryBlock::MAX_IMAGES)]);

        $this->assertCount(GalleryBlock::MAX_IMAGES, $block->refresh()->data['image_ids']);
    }

    /**
     * A photo band sits between sections without being one: no number, no
     * anchor, and the numbers around it stay consecutive.
     */
    public function test_a_photo_band_is_not_counted_as_a_section(): void
    {
        $site = $this->site();

        $this->block($site, 'about', ['heading' => 'About', 'body' => '<p>Words.</p>'], 0);
        $this->block($site, 'photo_band', ['image_id' => $this->image($site)->id, 'statement' => 'Where the road ends'], 1);
        $this->block($site, 'faq', ['items' => [['question' => 'Pick-up?', 'answer' => 'From Otjiwarongo.']]], 2);

        $html = $this->page($site);

        $this->assertStringContainsString('Where the road ends', $html);
        $this->assertStringContainsString('<span class="rule__num">01</span>', $html);
        $this->assertStringContainsString('<span class="rule__num">02</span>', $html);
        $this->assertStringNotContainsString('<span class="rule__num">03</span>', $html);
        $this->assertSame(1, substr_count($html, 'id="s1"'));
        $this->assertSame(1, substr_count($html, 'id="s2"'));
    }

    public function test_a_video_waits_to_be_asked_for(): void
    {
        $site = $this->site();
        $poster = $this->image($site);

        $this->block($site, 'video', ['items' => [
            ['key' => $site->mediaPrefix().'/videos/lions.mp4', 'poster_image_id' => $poster->id, 'caption' => 'Lions at the waterhole'],
            ['key' => $site->mediaPrefix().'/videos/road.mp4'],
        ]]);

        $html = $this->page($site);

        $this->assertStringContainsString('videos/lions.mp4"', $html);
        $this->assertStringContainsString('preload="none"', $html);
        // Without a poster: metadata and the first frame, not a black box.
        $this->assertStringContainsString('preload="metadata"', $html);
        $this->assertStringContainsString('videos/road.mp4#t=0.1"', $html);
        $this->assertStringNotContainsString('autoplay', $html);
        $this->assertStringContainsString('Lions at the waterhole', $html);
    }

    public function test_a_video_key_cannot_be_a_url(): void
    {
        $site = $this->site();

        $this->expectException(InvalidArgumentException::class);

        $this->block($site, 'video', ['items' => [['key' => 'https://example.com/x.mp4']]]);
    }

    public function test_one_person_is_a_portrait_several_are_a_row(): void
    {
        $site = $this->site();
        $block = $this->block($site, 'team', ['heading' => 'Meet your guide', 'items' => [
            ['name' => 'Markus', 'role' => 'Guide', 'text' => 'Twelve years in Etosha.'],
        ]]);

        $this->assertStringContainsString('class="team team--solo"', $this->page($site));

        $block->update(['data' => ['items' => [['name' => 'Markus'], ['name' => 'Anna']]]]);

        $this->assertStringNotContainsString('class="team team--solo"', $this->page($site));
    }

    public function test_quotes_and_questions_render_as_text(): void
    {
        $site = $this->site();

        $this->block($site, 'testimonials', ['items' => [
            ['quote' => 'Best <i>day</i> of the trip.', 'name' => 'Anna K.', 'origin' => 'Germany'],
        ]], 0);
        $this->block($site, 'faq', ['items' => [
            ['question' => 'Do you pick up?', 'answer' => 'Yes, in Otjiwarongo.'],
        ]], 1);

        $html = $this->page($site);

        $this->assertStringContainsString('Best &lt;i&gt;day&lt;/i&gt; of the trip.', $html);
        $this->assertStringContainsString('Anna K.', $html);
        $this->assertStringContainsString('<summary>Do you pick up?</summary>', $html);
    }

    public function test_an_itinerary_renders_each_stop_as_text(): void
    {
        $site = $this->site();

        $this->block($site, 'itinerary', ['heading' => 'The route', 'items' => [
            ['day' => 'Day 1', 'title' => 'Windhoek', 'stay' => 'Classic: Elegant Guesthouse'],
            ['day' => 'Days 3–4', 'title' => 'Sossusvlei', 'drive' => '350 km · 5–6 hours', 'text' => "Dunes <b>at dawn</b>.\nDeadvlei."],
        ]]);

        $html = $this->page($site);

        $this->assertSame(2, substr_count($html, '<li class="trip__stop reveal">'));
        $this->assertStringContainsString('<span class="trip__day">Days 3–4</span>', $html);
        $this->assertStringContainsString('350 km · 5–6 hours', $html);
        $this->assertStringContainsString('Dunes &lt;b&gt;at dawn&lt;/b&gt;.<br />', $html);
        $this->assertStringContainsString('Classic: Elegant Guesthouse', $html);
    }

    /**
     * A placeholder quote may win a meeting, never go live.
     */
    public function test_sample_quotes_are_tagged_and_block_publishing(): void
    {
        $site = $this->site();
        $block = $this->block($site, 'testimonials', ['items' => [
            ['quote' => 'Not one day rushed.', 'name' => 'Anna', 'sample' => true],
        ]]);

        $this->assertStringContainsString('<em class="quote__sample">Sample</em>', $this->page($site));

        $gate = app(PublishGate::class);
        $this->assertNotEmpty(array_filter($gate->blockers($site), fn ($b) => str_contains($b, 'sample quotes')));

        $block->update(['data' => ['items' => [['quote' => 'Not one day rushed.', 'name' => 'Anna']]]]);

        $this->assertSame([], array_filter($gate->blockers($site), fn ($b) => str_contains($b, 'sample quotes')));
    }

    /**
     * A logo taller than the bar hangs from its top instead of being centred
     * off the top of the screen, and its shadow is the site's choice.
     */
    public function test_a_large_logo_hangs_into_the_hero_with_its_own_shadow(): void
    {
        $site = $this->site();
        $site->update(['logo_key' => $site->mediaPrefix().'/logo.png', 'logo_hero_height' => 120, 'logo_shadow' => 'shadow']);
        $this->block($site, 'hero', ['headline' => 'Hello']);

        $html = $this->page($site);

        $this->assertStringContainsString('class="nav__logo"', $html);
        $this->assertMatchesRegularExpression('/\.nav__name--logo \{[^}]*align-self: flex-start/', $html);
        $this->assertStringContainsString('filter: drop-shadow(0 6px 18px rgba(0,0,0,.5));', $html);
        $this->assertStringContainsString('height: min(120px, 112px);', $html);
    }

    public function test_the_enquiry_button_can_sit_in_the_bar_without_a_twin_link(): void
    {
        $site = $this->site();
        $site->update(['action_buttons' => ['enquiry' => ['places' => ['menu.desktop', 'footer.phone']]]]);
        $this->block($site, 'hero', ['headline' => 'Hello'], 0);
        $this->block($site, 'about', ['heading' => 'About', 'body' => '<p>Words.</p>'], 1);
        $this->block($site, 'enquiry', ['heading' => 'Plan your safari', 'form_type' => 'contact', 'button_label' => 'Plan your safari'], 2);

        $html = $this->page($site);
        preg_match('#<header.*?</header>#s', $html, $header);

        $this->assertMatchesRegularExpression('/class="btn nav__cta[^"]*"\s+href="#s2"\s*>Plan your safari/', $header[0]);
        $this->assertStringContainsString('class="nav__link--action nav__link--dup"', $header[0]);
    }

    public function test_a_deleted_picture_leaves_the_items_that_used_it(): void
    {
        $site = $this->site();
        $kept = $this->image($site);
        $gone = $this->image($site);

        $block = $this->block($site, 'offers', ['items' => [
            ['title' => 'A', 'image_id' => $gone->id],
            ['title' => 'B', 'image_id' => $kept->id],
        ]]);

        $method = new ReflectionMethod(EditSiteImagesAction::class, 'write');
        $method->setAccessible(true);
        $method->invoke(null, $site, [['id' => $kept->id, 'key' => $kept->key]]);

        $items = $block->refresh()->data['items'];

        $this->assertNull($items[0]['image_id']);
        $this->assertSame($kept->id, $items[1]['image_id']);
    }

    public function test_what_the_page_does_not_ship(): void
    {
        $site = $this->site();
        $this->block($site, 'hero', ['headline' => 'Hello']);

        $html = $this->page($site);

        preg_match('#<style>(.*?)</style>#s', $html, $css);

        $this->assertNotEmpty($css[1] ?? '');
        $this->assertStringNotContainsString('/*', $css[1]);
        $this->assertDoesNotMatchRegularExpression('/\n[ \t]+</', $html);
    }

    public function test_whitespace_is_kept_where_it_is_content(): void
    {
        $html = "<div>\n    <p>a</p>\n    <textarea>\n  kept\n</textarea>\n  <pre>\n  also kept</pre></div>";

        $this->assertSame(
            "<div>\n<p>a</p>\n<textarea>\n  kept\n</textarea>\n<pre>\n  also kept</pre></div>",
            HtmlWhitespace::strip($html)
        );
    }

    private function site(): Site
    {
        $site = Site::factory()->create();
        SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        return $site;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function block(Site $site, string $type, array $data, int $sort = 0): SiteBlock
    {
        return SiteBlock::create([
            'site_page_id' => $site->pages()->first()->id,
            'type' => $type,
            'data' => $data,
            'sort' => $sort,
        ]);
    }

    private function image(Site $site): SiteImage
    {
        return SiteImage::create([
            'site_id' => $site->id,
            'key' => $site->mediaPrefix().'/'.uniqid('photo-').'.jpg',
            'sort' => 0,
        ]);
    }

    private function page(Site $site): string
    {
        return (string) $this->get('/_sites/'.$site->slug.'?preview='.$site->draft_token)->assertOk()->getContent();
    }
}
