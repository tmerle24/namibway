<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the public renderer must and must not do.
 *
 * Three of these are promises rather than preferences. A draft is research
 * about somebody's business and must not be reachable without its token; a
 * customer's page must not ship the travel platform's JavaScript, because the
 * flyer sells an old phone on a slow connection; and a block with nothing in it
 * must not render as an empty band, because that is what makes a generated site
 * look unfinished rather than sparse.
 */
class SiteRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function siteWithPage(array $attributes = []): Site
    {
        $site = Site::factory()->create($attributes);
        SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        return $site;
    }

    private function block(Site $site, string $type, array $data, int $sort = 0): SiteBlock
    {
        return SiteBlock::create([
            'site_page_id' => $site->pages()->first()->id,
            'type' => $type,
            'data' => $data,
            'sort' => $sort,
        ]);
    }

    public function test_a_published_site_renders_at_the_path_fallback(): void
    {
        $site = $this->siteWithPage(['name' => 'Okonjima Bush Camp']);
        $site->forceFill(['status' => 'published', 'published_at' => now()])->save();

        $this->block($site, 'hero', ['headline' => 'Where the leopards are']);

        $this->get('/_sites/'.$site->slug)
            ->assertOk()
            ->assertSee('Where the leopards are')
            ->assertSee('Okonjima Bush Camp');
    }

    public function test_a_draft_is_not_reachable_without_its_token(): void
    {
        $site = $this->siteWithPage();
        $this->block($site, 'hero', ['headline' => 'Draft headline']);

        $this->get('/_sites/'.$site->slug)->assertNotFound();
        $this->get('/_sites/'.$site->slug.'?preview=wrong')->assertNotFound();

        $this->get('/_sites/'.$site->slug.'?preview='.$site->draft_token)
            ->assertOk()
            ->assertSee('Draft headline');
    }

    public function test_a_draft_says_noindex_and_a_published_site_does_not(): void
    {
        $draft = $this->siteWithPage();
        $this->block($draft, 'hero', ['headline' => 'Draft']);

        $response = $this->get('/_sites/'.$draft->slug.'?preview='.$draft->draft_token);
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertSee('noindex, nofollow', false);

        $live = $this->siteWithPage();
        $live->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $this->block($live, 'hero', ['headline' => 'Live']);

        $this->get('/_sites/'.$live->slug)
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_a_customer_page_ships_none_of_the_travel_platform(): void
    {
        $site = $this->siteWithPage();
        $this->block($site, 'hero', ['headline' => 'Hello']);

        $html = $this->get('/_sites/'.$site->slug.'?preview='.$site->draft_token)
            ->assertOk()
            ->getContent();

        // The Inertia root view's fingerprints. None of them belongs on a page
        // sold as loading over a weak mobile connection.
        $this->assertStringNotContainsString('@vite', $html);
        $this->assertStringNotContainsString('/build/assets', $html);
        $this->assertStringNotContainsString('data-page=', $html);
        $this->assertStringNotContainsString('app.js', $html);
    }

    public function test_a_block_with_nothing_in_it_does_not_render(): void
    {
        $site = $this->siteWithPage();
        $this->block($site, 'hero', ['headline' => 'Hello'], 0);
        // Enabled, valid, and completely empty — exactly what generation leaves
        // behind when a listing had no opening hours.
        $this->block($site, 'opening_hours', ['heading' => 'Opening hours', 'days' => []], 1);

        $this->get('/_sites/'.$site->slug.'?preview='.$site->draft_token)
            ->assertOk()
            ->assertDontSee('Opening hours');
    }

    public function test_a_disabled_block_does_not_render(): void
    {
        $site = $this->siteWithPage();
        $this->block($site, 'hero', ['headline' => 'Hello'], 0);

        $block = $this->block($site, 'rich_text', ['heading' => 'Small print', 'body' => 'Terms'], 1);
        $block->forceFill(['is_enabled' => false])->save();

        $this->get('/_sites/'.$site->slug.'?preview='.$site->draft_token)
            ->assertOk()
            ->assertDontSee('Small print');
    }

    public function test_a_withdrawn_block_type_does_not_break_a_live_page(): void
    {
        $site = $this->siteWithPage();
        $this->block($site, 'hero', ['headline' => 'Still here']);

        // Written straight to the table, the way a row left behind by a
        // withdrawn type would look after the class was deleted.
        DB::table('site_blocks')->insert([
            'site_page_id' => $site->pages()->first()->id,
            'type' => 'weather_widget',
            'data' => '{}',
            'sort' => 1,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/_sites/'.$site->slug.'?preview='.$site->draft_token)
            ->assertOk()
            ->assertSee('Still here');
    }

    public function test_the_page_is_readable_without_javascript(): void
    {
        $site = $this->siteWithPage();
        $this->block($site, 'hero', ['headline' => 'Readable'], 0);
        $this->block($site, 'about', ['heading' => 'About us', 'body' => 'We fix bakkies.'], 1);

        $html = $this->get('/_sites/'.$site->slug.'?preview='.$site->draft_token)->getContent();

        // The reveal styles only apply under `.js`, which the page's own script
        // adds. Without scripting the content is simply visible — so the class
        // must never be in the served markup.
        $this->assertStringNotContainsString('<html class="js"', $html);
        $this->assertStringContainsString('We fix bakkies.', $html);
    }
}
