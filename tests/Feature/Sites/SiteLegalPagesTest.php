<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Privacy, the legal notice, and the strip they hang off.
 *
 * These are the pages nobody reads until somebody does, and the two things
 * that must hold are the boring ones: they are always reachable from the foot
 * of the site, and what they say is the business's, so it is escaped rather
 * than rendered — a paste out of a word processor must not be able to put a
 * script on their own website.
 */
class SiteLegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private function site(array $attributes = []): Site
    {
        $site = Site::factory()->create($attributes);

        $page = SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'footer',
            'data' => [],
            'sort' => 90,
        ]);

        return $site;
    }

    public function test_the_footer_links_privacy_and_the_legal_notice_and_says_who_built_it(): void
    {
        $site = $this->site();

        $this->get($site->publicUrl())
            ->assertSee('Privacy')
            ->assertSee('Legal notice')
            ->assertSee('Powered by')
            ->assertSee('NamibWay');
    }

    public function test_both_legal_pages_render_and_can_be_left_again(): void
    {
        $site = $this->site(['legal_privacy' => 'We keep what you send us.']);

        $this->get($site->pageUrl('privacy'))
            ->assertOk()
            ->assertSee('We keep what you send us.')
            ->assertSee('Back to the site');

        $this->get($site->pageUrl('legal'))->assertOk();
    }

    /**
     * The token has to survive the extra path segment — appending one onto a
     * `?preview=` address produces a URL that resolves to nothing.
     */
    public function test_a_draft_legal_page_needs_the_token_like_every_other_page(): void
    {
        $site = $this->site();

        $this->assertStringContainsString('preview='.$site->draft_token, $site->pageUrl('privacy'));

        $this->get('/_sites/'.$site->slug.'/privacy')->assertNotFound();
    }

    public function test_the_business_owns_this_text_so_it_is_never_markup(): void
    {
        $site = $this->site(['legal_privacy' => 'Careful: <script>alert(1)</script>']);

        $this->get($site->pageUrl('privacy'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_copyright_line_is_the_business_name_until_it_is_changed(): void
    {
        $site = $this->site(['name' => 'Dune Edge Lodge']);

        $this->get($site->publicUrl())->assertSee('Dune Edge Lodge');

        $site->update(['legal_copyright' => 'Dune Edge Lodge (Pty) Ltd']);

        $this->get($site->publicUrl())->assertSee('Dune Edge Lodge (Pty) Ltd');
    }

    /**
     * Offered to a crawler in one file and denied in another is a contradiction,
     * not a policy.
     */
    public function test_the_legal_pages_stay_out_of_the_sitemap(): void
    {
        $site = Site::factory()->published()->onHost('lodge.example.test')->create();

        SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        $this->get('http://lodge.example.test/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/privacy');
    }
}
