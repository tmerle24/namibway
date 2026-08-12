<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\Typography;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a customer website is set in.
 *
 * A business with branding of its own has usually already chosen a face, so
 * both roles are settable and the name in the bar gets a third choice — it is
 * the one piece of type that has to hold up white, small, and over an arbitrary
 * photograph. The size of the name is computed from its length unless somebody
 * says otherwise, because the bar has exactly one line to fit it on.
 */
class SiteTypographyTest extends TestCase
{
    use RefreshDatabase;

    private function site(array $attributes = []): Site
    {
        $site = Site::factory()->create($attributes);
        $page = SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'about',
            'data' => ['heading' => 'About us', 'body' => 'Words.'],
            'sort' => 0,
        ]);

        return $site;
    }

    public function test_a_site_with_no_choices_gets_the_house_faces(): void
    {
        $site = $this->site(['name' => 'Dune Edge']);

        $this->get($site->publicUrl())
            ->assertSee("--font-display: 'Iowan Old Style'", false)
            ->assertSee('--font-body: system-ui', false)
            // The name follows the body face by default, not the display one.
            ->assertSee('--font-brand: system-ui', false);
    }

    public function test_each_role_can_be_chosen_separately(): void
    {
        $site = $this->site([
            'name' => 'Dune Edge',
            'font_display' => 'georgia',
            'font_body' => 'grotesk',
            'brand_font' => 'verdana',
        ]);

        $this->get($site->publicUrl())
            ->assertSee('--font-display: Georgia', false)
            ->assertSee("--font-body: 'Helvetica Neue'", false)
            ->assertSee('--font-brand: Verdana', false);
    }

    /**
     * Calibrated against a real one: "Ongombo West #56 Hunting Safari" is 31
     * characters and wants 18px — smaller and it disappears into the
     * photograph, larger and it crowds a six-item menu.
     */
    public function test_the_name_is_sized_by_how_long_it_is(): void
    {
        $this->assertSame(20, Typography::brandSize(new Site(['name' => 'Dune Edge'])));
        $this->assertSame(18, Typography::brandSize(new Site(['name' => 'Ongombo West #56 Hunting Safari'])));
        $this->assertSame(16, Typography::brandSize(new Site(['name' => str_repeat('a', 40)])));
    }

    public function test_a_chosen_size_wins_over_the_computed_one(): void
    {
        $site = $this->site(['name' => 'Ongombo West #56 Hunting Safari', 'brand_size' => 24]);

        $this->get($site->publicUrl())->assertSee('--brand-size: 24px', false);
    }

    /**
     * An unknown key must fall back rather than emit nothing — a blank
     * `font-family:` would take the page down to the browser's default serif.
     */
    public function test_a_face_that_is_not_on_the_list_falls_back(): void
    {
        $site = $this->site(['name' => 'Dune Edge', 'font_body' => 'comic-sans']);

        $this->get($site->publicUrl())->assertSee('--font-body: system-ui', false);
    }

    /**
     * Every face has to be a stack the reader already has: these pages paint on
     * the first frame rather than waiting on a download, and that is worth more
     * on the connections they are built for than any particular face is.
     */
    public function test_no_face_pulls_a_webfont(): void
    {
        foreach (Typography::faces() as $key => $face) {
            $this->assertStringNotContainsString('url(', $face['stack'], $key);
            $this->assertStringNotContainsString('@import', $face['stack'], $key);
            $this->assertNotEmpty($face['label'], $key);
        }
    }
}
