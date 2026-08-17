<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\Rendering\StoryText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The promise the about block makes: a long text is shown a paragraph at a time,
 * with the whole of it one click away.
 *
 * It was only kept for text that arrived as `<p>` markup, which most of it does
 * not — a pasted or scraped description is stored as plain text, and
 * Listing::sanitizeRichText escapes a value with no tags rather than purifying
 * it, so there was no `<p>` to split on and the entire text rendered as one
 * unbroken wall. Found on a live partner site (a shop whose About us ran to
 * about 1,200 characters in a single run of sentences).
 */
class SiteAboutStoryTest extends TestCase
{
    use RefreshDatabase;

    /** The failing case, in the shape it actually arrives: no markup, no breaks. */
    private const RUN_ON = 'About Us We are a passionate, afrocentric brand dedicated to celebrating the '
        .'rich cultural tapestry of Africa through exquisite, handcrafted creations. Our curated collection '
        .'features high-quality African jewelry, masterfully woven baskets, traditional Ndebele bags, and a '
        .'unique array of vibrant accessories. Each piece in our collection is more than just an item — it is '
        .'a storyteller, carrying a piece of timeless African tradition, resilience, and artistry. Our Mission '
        .'At MONAS Collection, our mission is to preserve and promote traditional craftsmanship while '
        .'empowering local artisans. By blending deep-rooted cultural techniques with modern aesthetics, we '
        .'bring you authentic, high-quality statement pieces that allow you to carry a touch of Africa '
        .'wherever you go. Why Choose Us? 100% Handcrafted: Every item is meticulously crafted by skilled '
        .'hands, making each piece uniquely yours.Culturally Inspired: From the bold geometric patterns of '
        .'Ndebele heritage to classic woven textures, our designs honor authentic African roots.';

    private function publishedSiteWithAbout(array $data): Site
    {
        $site = Site::factory()->create(['name' => 'OC Monas Collection']);
        $site->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $page = SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'about',
            'data' => $data + ['heading' => 'About us', 'slideshow' => true],
            'sort' => 0,
        ]);

        return $site;
    }

    public function test_a_long_unmarked_up_text_is_shown_as_a_slideshow(): void
    {
        $site = $this->publishedSiteWithAbout(['body' => self::RUN_ON]);

        $response = $this->get('/_sites/'.$site->slug)->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('data-story-track', $html, 'A long about text must page, not wall.');
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'data-story-dot='));
        $this->assertStringContainsString('Read the full story', $html);
    }

    public function test_the_full_text_survives_being_cut_into_slides(): void
    {
        $slides = StoryText::slides(self::RUN_ON);

        $this->assertGreaterThanOrEqual(2, count($slides));

        // Nothing may be lost on the way — a slide boundary is allowed to add
        // whitespace and nothing else.
        $strip = fn (string $value): string => preg_replace('/\s+/u', '', strip_tags($value)) ?? '';

        $this->assertSame($strip(self::RUN_ON), $strip(implode('', $slides)));
    }

    public function test_plain_text_paragraphs_are_recovered_from_newlines(): void
    {
        // Newlines are invisible in HTML, which is how a text written as three
        // paragraphs rendered as one.
        $slides = StoryText::slides("The first thing.\n\nThe second thing.\n\nThe third thing.");

        $this->assertCount(3, $slides);
        $this->assertSame('<p>The first thing.</p>', $slides[0]);
    }

    public function test_a_paragraph_that_is_not_a_paragraph_still_gets_a_slide(): void
    {
        // The old regex matched <p>…</p> and only that, so this list was on no
        // slide at all — it vanished from the page it was written for.
        $slides = StoryText::slides('<p>What we sell.</p><ul><li>Baskets</li></ul><p>Where to find us.</p>');

        $this->assertCount(3, $slides);
        $this->assertStringContainsString('Baskets', $slides[1]);
    }

    public function test_a_story_wrapped_in_one_container_is_stepped_inside(): void
    {
        // `div` is in the purifier's allow-list, so pasted markup arrives like
        // this. Treating the wrapper as the paragraph makes every such text one
        // slide, which is the same wall in a different shape.
        $slides = StoryText::slides('<div><p>What we sell.</p><p>Where to find us.</p></div>');

        $this->assertCount(2, $slides);
    }

    public function test_a_photograph_is_not_an_empty_paragraph(): void
    {
        // Emptiness is not "has no words" — a paragraph holding only a picture
        // is content, and dropping it deletes it from the page.
        $slides = StoryText::slides('<p><img src="basket.jpg" alt="A woven basket"></p><p>Woven in Kavango.</p>');

        $this->assertCount(2, $slides);
        $this->assertStringContainsString('basket.jpg', $slides[0]);
    }

    public function test_a_heading_travels_with_the_text_under_it(): void
    {
        $slides = StoryText::slides('<h3>Our mission</h3><p>To preserve craftsmanship.</p><h3>Why us</h3><p>Handcrafted.</p>');

        $this->assertCount(2, $slides);
        $this->assertStringContainsString('Our mission', $slides[0]);
        $this->assertStringContainsString('To preserve craftsmanship.', $slides[0]);
    }

    public function test_a_short_text_is_left_alone(): void
    {
        // One paragraph is not a story, and inventing slides for it would put
        // navigation under two lines of text.
        $this->assertSame([], StoryText::slides('A small shop in Windhoek.'));
        $this->assertSame([], StoryText::slides('<p>A small shop in Windhoek.</p>'));
    }

    public function test_the_owner_can_turn_the_slideshow_off(): void
    {
        $site = $this->publishedSiteWithAbout(['body' => self::RUN_ON, 'slideshow' => false]);

        $this->get('/_sites/'.$site->slug)
            ->assertOk()
            ->assertDontSee('data-story-track', false)
            ->assertSee('afrocentric brand', false);
    }

    public function test_the_full_story_page_renders_the_paragraphs_too(): void
    {
        $site = $this->publishedSiteWithAbout(['body' => "The first thing.\n\nThe second thing."]);

        $response = $this->get('/_sites/'.$site->slug.'/about')->assertOk();

        $this->assertStringContainsString('<p>The first thing.</p>', $response->getContent());
    }
}
