<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\HighlightIcon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The line marks above the highlights.
 *
 * Highlights are short phrases — a lodge's own, or its amenity list — and they
 * render as a column of headings that reads as a list of tags. A mark above
 * each one turns that into a feature of the page, but only under two rules:
 * nothing is drawn when nothing is recognised, and the marks are all-or-nothing
 * across the column.
 */
class HighlightIconTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recognises_the_phrases_these_businesses_actually_use(): void
    {
        $expected = [
            'Wi-Fi in every room' => 'wifi',
            'Swimming pool' => 'pool',
            'Air conditioning' => 'snowflake',
            'A/C' => 'snowflake',
            'Airport transfer' => 'car',
            'Guided dune walks' => 'boot',
            'Dinner cooked on the coals' => 'restaurant',
            'Game drives' => 'jeep',
            '4x4 self-drive' => 'jeep',
            'Braai facilities' => 'fire',
            'Sundowners at the waterhole' => 'bar',
            'Stargazing' => 'star',
            'Family rooms' => 'family',
            'Credit cards accepted' => 'card',
        ];

        foreach ($expected as $phrase => $icon) {
            $this->assertSame($icon, HighlightIcon::for($phrase), $phrase);
        }
    }

    /**
     * The three that a real site produced and the first version missed: the
     * accommodation word this market actually uses, the catering word, and a
     * camp fire written as two words when only the compound was listed. Nought
     * out of three meant the whole column went undrawn — which is the
     * all-or-nothing rule working correctly on a vocabulary that was too small.
     */
    public function test_it_knows_the_words_a_namibian_lodge_writes(): void
    {
        $expected = [
            'lodge' => 'bed',
            'catering' => 'restaurant',
            'camp fire' => 'fire',
            'Guest house' => 'bed',
            'Camping' => 'tent',
            'Tented camp' => 'tent',
            'Boma dinners' => 'fire',
            'Lapa' => 'fire',
            'Buffet dinner' => 'restaurant',
        ];

        foreach ($expected as $phrase => $icon) {
            $this->assertSame($icon, HighlightIcon::for($phrase), $phrase);
        }

        $this->assertTrue(HighlightIcon::worthDrawing(['lodge', 'catering', 'camp fire']));
    }

    /**
     * Self-catering is a kind of accommodation, not a kitchen that cooks for
     * you — the one place these two vocabularies collide.
     */
    public function test_self_catering_is_a_bed_and_not_a_restaurant(): void
    {
        $this->assertSame('bed', HighlightIcon::for('Self-catering chalets'));
        $this->assertSame('bed', HighlightIcon::for('self catering'));
        $this->assertSame('restaurant', HighlightIcon::for('Catering for groups'));
    }

    /**
     * A generic mark beside "Trophy hunting" says nothing and costs the whole
     * set its credibility, so nothing is the answer.
     */
    public function test_an_unrecognised_phrase_gets_no_mark(): void
    {
        $this->assertNull(HighlightIcon::for('Trophy hunting'));
        $this->assertNull(HighlightIcon::for('Something nobody else offers'));
    }

    /**
     * The reason every pattern is anchored on word boundaries.
     */
    public function test_a_word_inside_another_word_is_not_a_match(): void
    {
        $this->assertNotSame('bar', HighlightIcon::for('Harbour views'));
        $this->assertNull(HighlightIcon::for('Space for events'));
    }

    public function test_the_marks_are_all_or_nothing_across_a_column(): void
    {
        $this->assertTrue(HighlightIcon::worthDrawing([
            'Wi-Fi', 'Swimming pool', 'Restaurant', 'Airport transfer',
        ]));

        $this->assertFalse(HighlightIcon::worthDrawing([
            'Trophy hunting', 'Space for events', 'Something else', 'Wi-Fi',
        ]));

        $this->assertFalse(HighlightIcon::worthDrawing([]));
    }

    /**
     * A key the matcher can return with no `@case` to draw it is an empty
     * `<svg>` on somebody's page.
     */
    public function test_every_mark_the_matcher_can_choose_can_be_drawn(): void
    {
        $partial = file_get_contents(resource_path('views/sites/partials/icon.blade.php'));

        $this->assertIsString($partial);

        foreach (HighlightIcon::keys() as $key) {
            $this->assertStringContainsString('@case(\''.$key.'\')', $partial, $key.' has no drawing');
        }
    }

    public function test_a_recognised_column_is_drawn_and_an_unrecognised_one_is_not(): void
    {
        $drawn = $this->siteWithHighlights('drawn', [
            'Wi-Fi', 'Swimming pool', 'Guided dune walks', 'Braai facilities',
        ]);

        $this->get($drawn->publicUrl())->assertSee('class="card__icon"', false);

        $bare = $this->siteWithHighlights('bare', [
            'Trophy hunting', 'Space for events', 'Something else', 'And another',
        ]);

        $this->get($bare->publicUrl())->assertDontSee('class="card__icon"', false);
    }

    /**
     * @param  array<int, string>  $titles
     */
    private function siteWithHighlights(string $slug, array $titles): Site
    {
        $site = Site::factory()->create(['slug' => $slug]);
        $page = SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'highlights',
            'data' => [
                'heading' => 'What we offer',
                'items' => array_map(fn (string $title): array => ['title' => $title, 'text' => null], $titles),
            ],
            'sort' => 0,
        ]);

        return $site;
    }

    /**
     * On one column the mark sits beside the words. Stacked, a 24px icon left a
     * card's whole width empty to its right, which on a phone is most of the
     * screen. Once the cards are in columns there is no width to waste and the
     * mark goes back on top.
     */
    public function test_the_mark_sits_beside_the_words_on_one_column(): void
    {
        $site = $this->siteWithHighlights('rowed', ['Wi-Fi', 'Swimming pool', 'Braai facilities']);

        $html = $this->get($site->publicUrl())->assertOk()->getContent();

        $this->assertIsString($html);
        // The words are wrapped so the icon has something to sit next to.
        $this->assertStringContainsString('class="card__body"', $html);
        $this->assertMatchesRegularExpression('/\.card \{[^}]*display: flex/s', $html);
        $this->assertMatchesRegularExpression('/min-width: 640px\)[^@]*\.card \{ display: block/s', $html);
    }
}
