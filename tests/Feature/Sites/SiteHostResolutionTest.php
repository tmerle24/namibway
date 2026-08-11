<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\SiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Host resolution, and the two ways it could go badly wrong.
 *
 * The travel platform's routes carry no host constraint, so whatever decides
 * "this host is a customer's site" has to be right in both directions: a
 * customer's domain must reach their site rather than the travel home page, and
 * namibway.com must never reach a customer's site whatever the database says.
 */
class SiteHostResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function publishedSiteOn(string $host): Site
    {
        $site = Site::factory()->published()->onHost($host)->create();
        $page = SitePage::factory()->create(['site_id' => $site->id]);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'hero',
            'data' => ['headline' => 'On its own host'],
            'sort' => 0,
        ]);

        return $site;
    }

    public function test_a_request_on_a_sites_host_renders_that_site(): void
    {
        $this->publishedSiteOn('bakkie-repairs.websites.namibway.com');

        $this->get('http://bakkie-repairs.websites.namibway.com/')
            ->assertOk()
            ->assertSee('On its own host');
    }

    public function test_the_www_form_of_a_custom_domain_resolves_too(): void
    {
        $this->publishedSiteOn('bakkie-repairs.com.na');

        $this->get('http://www.bakkie-repairs.com.na/')
            ->assertOk()
            ->assertSee('On its own host');
    }

    public function test_the_platform_host_is_never_a_site(): void
    {
        // The typo with the unmissable blast radius: a row claiming the app's
        // own host would otherwise replace the travel platform.
        config(['sites.reserved_hosts' => ['namibway.test']]);
        $this->publishedSiteOn('namibway.test');

        $resolver = app(SiteResolver::class);

        $this->assertNull($resolver->forHost('namibway.test'));
        $this->assertNull($resolver->forHost('www.namibway.test'));
    }

    public function test_an_unknown_host_falls_through_to_the_platform(): void
    {
        $this->publishedSiteOn('somebody-else.com.na');

        $resolver = app(SiteResolver::class);

        $this->assertNull($resolver->forHost('unclaimed.com.na'));
    }

    public function test_platform_paths_still_answer_on_a_site_host(): void
    {
        $this->publishedSiteOn('lodge.websites.namibway.com');

        // Images are emitted root-relative so they travel over the connection
        // the browser already has, which means /thumbs has to keep belonging to
        // the platform on a customer's host — otherwise every picture on every
        // customer site goes blank. /up is the same passthrough list and is the
        // one entry that can be checked without a bucket behind it.
        $this->get('http://lodge.websites.namibway.com/up')->assertOk();

        $this->assertContains('thumbs/*', (array) config('sites.passthrough_paths'));
    }

    public function test_the_host_map_notices_a_site_being_written(): void
    {
        $resolver = app(SiteResolver::class);

        $this->assertNull($resolver->forHost('late.websites.namibway.com'));

        $this->publishedSiteOn('late.websites.namibway.com');

        // Cached forever, so the observer is the only thing standing between a
        // new customer and an unreachable website.
        $this->assertNotNull($resolver->forHost('late.websites.namibway.com'));
    }
}
