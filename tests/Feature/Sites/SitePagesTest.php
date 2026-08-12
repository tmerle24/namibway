<?php

namespace Tests\Feature\Sites;

use App\Filament\Support\EditPagesAction;
use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A site with more than one page.
 *
 * The renderer could always serve them — `SiteController` resolves a slug
 * against `site_pages` and the route carries `{page?}` — but nothing could make
 * a second one, so every site was a single scrolling page whether or not that
 * suited the business.
 *
 * What is protected here is mostly the front page: it is what answers at the
 * root of the domain, what the draft link points at and what `sites:generate`
 * writes into, so it cannot be removed and cannot be moved off the root. And a
 * slug the renderer answers itself — the legal pages, robots, the sitemap —
 * would produce a page that saves and then never appears, which is worse than
 * being told no.
 */
class SitePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_page_is_created_and_renders(): void
    {
        $site = $this->site();
        $home = $this->home($site);

        $this->write($site, [
            ['id' => $home->id, 'title' => 'Home'],
            ['title' => 'Our Safaris'],
        ]);

        $page = $site->pages()->where('is_home', false)->sole();

        $this->assertSame('our-safaris', $page->slug);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'rich_text',
            'data' => ['heading' => 'Our Safaris', 'body' => '<p>Three nights in Etosha.</p>'],
            'sort' => 0,
        ]);

        $this->get("/_sites/{$site->slug}/our-safaris?preview={$site->draft_token}")
            ->assertOk()
            ->assertSee('Three nights in Etosha.', false);
    }

    public function test_the_menu_carries_the_pages_and_marks_the_one_being_read(): void
    {
        $site = $this->site();
        $home = $this->home($site);

        $this->write($site, [
            ['id' => $home->id, 'title' => 'Home'],
            ['title' => 'Our Safaris'],
        ]);

        $response = $this->get("/_sites/{$site->slug}?preview={$site->draft_token}");

        $response->assertOk()
            ->assertSee('Our Safaris', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_the_front_page_cannot_be_taken_away(): void
    {
        $site = $this->site();
        $home = $this->home($site);

        // A state that has forgotten the home page entirely — the form cannot
        // produce this, and if it ever did the site would lose its front page.
        $this->write($site, [['title' => 'Our Safaris']]);

        $this->assertTrue($site->pages()->whereKey($home->id)->exists());
    }

    public function test_the_front_page_keeps_the_root_whatever_is_typed(): void
    {
        $site = $this->site();
        $home = $this->home($site);

        $this->write($site, [['id' => $home->id, 'title' => 'Welcome', 'slug' => 'somewhere-else']]);

        $home->refresh();

        $this->assertSame('', $home->slug);
        $this->assertSame('Welcome', $home->title);
    }

    public function test_a_slug_the_site_answers_itself_is_refused(): void
    {
        $site = $this->site();
        $home = $this->home($site);

        foreach (['privacy', 'legal', 'robots.txt', 'sitemap.xml', 'enquiry'] as $slug) {
            $refused = false;

            try {
                $this->write($site, [
                    ['id' => $home->id, 'title' => 'Home'],
                    ['title' => 'Something', 'slug' => $slug],
                ]);
            } catch (Halt) {
                $refused = true;
            }

            $this->assertTrue($refused, "A page at /{$slug} would never be seen, so it must be refused.");
            $this->assertSame(1, $site->pages()->count());
        }
    }

    public function test_two_pages_cannot_share_an_address(): void
    {
        $site = $this->site();
        $home = $this->home($site);

        $this->expectException(Halt::class);

        $this->write($site, [
            ['id' => $home->id, 'title' => 'Home'],
            ['title' => 'Rooms'],
            ['title' => 'Rooms'],
        ]);
    }

    /**
     * The blocks are the page's content and belong nowhere else, so they go
     * with it rather than being left as rows pointing at a page that is gone.
     */
    public function test_removing_a_page_takes_its_bands_with_it(): void
    {
        $site = $this->site();
        $home = $this->home($site);

        $this->write($site, [['id' => $home->id, 'title' => 'Home'], ['title' => 'Rooms']]);

        $rooms = $site->pages()->where('is_home', false)->sole();
        SiteBlock::create(['site_page_id' => $rooms->id, 'type' => 'rich_text', 'data' => ['body' => 'x'], 'sort' => 0]);

        $this->write($site, [['id' => $home->id, 'title' => 'Home']]);

        $this->assertSame(0, SiteBlock::where('site_page_id', $rooms->id)->count());
        $this->assertFalse($site->pages()->whereKey($rooms->id)->exists());
    }

    /**
     * The bar has a fixed height and the hero is pulled up under it by exactly
     * that many pixels, so a menu allowed to grow leaves a strip of page
     * background above the photograph. That was a real regression once; pages
     * are one more thing that could cause it.
     */
    public function test_a_site_with_many_pages_does_not_grow_the_bar(): void
    {
        $site = $this->site();
        $home = $this->home($site);

        $state = [['id' => $home->id, 'title' => 'Home']];

        foreach (['Rooms', 'Rates', 'Safaris', 'Birding', 'Getting here', 'Gallery', 'Reviews'] as $title) {
            $state[] = ['title' => $title];
        }

        $this->write($site, $state);

        $html = $this->get("/_sites/{$site->slug}?preview={$site->draft_token}")->assertOk()->getContent();

        preg_match('~<nav class="nav__links">(.*?)</nav>~s', (string) $html, $matches);

        $this->assertSame(6, substr_count($matches[1] ?? '', '<a '));
    }

    private function site(): Site
    {
        $listing = Listing::factory()->create();

        return Site::factory()->create(['source_listing_id' => $listing->id, 'slug' => 'dune-edge']);
    }

    private function home(Site $site): SitePage
    {
        return SitePage::factory()->create([
            'site_id' => $site->id,
            'is_home' => true,
            'slug' => '',
            'locale' => $site->default_locale,
            'title' => $site->name,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     */
    private function write(Site $site, array $state): void
    {
        $method = new ReflectionMethod(EditPagesAction::class, 'write');
        $method->setAccessible(true);
        $method->invoke(null, $site, $state);
    }
}
