<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SiteImage;
use App\Models\SitePage;
use App\Sites\Import\SitePackage;
use App\Sites\Import\SitePackageImporter;
use App\Sites\SiteEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * The enterprise edition: subpages from a package, a hero video, the numbers
 * band and the showcase layer. Added 2026-09-17 for the first enterprise site.
 */
class SiteEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    private string $zip;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
    }

    protected function tearDown(): void
    {
        if (isset($this->zip) && is_file($this->zip)) {
            unlink($this->zip);
        }

        parent::tearDown();
    }

    public function test_a_package_creates_subpages_with_their_own_bands(): void
    {
        $plan = $this->importer()->apply($this->package($this->manifest()));
        $this->assertTrue($plan->written, implode(' ', $plan->errors));

        $site = Site::findOrFail($plan->siteId);
        $this->assertSame(SiteEdition::Enterprise, $site->edition);

        $page = $site->pages()->where('slug', 'tours/etosha')->sole();
        $this->assertFalse($page->is_home);
        $this->assertFalse($page->show_in_nav);
        $this->assertSame('Etosha', $page->nav_label);
        $this->assertSame(['hero', 'itinerary', 'enquiry'], $page->blocks()->pluck('type')->all());

        $lion = $site->images()->where('key', $site->mediaPrefix().'/lion.jpg')->sole();
        $this->assertSame($lion->id, $page->blocks()->where('type', 'hero')->sole()->data['image_id']);

        // The hero video is uploaded and stored as a key.
        $hero = $site->pages()->where('is_home', true)->sole()->blocks()->where('type', 'hero')->sole();
        $this->assertSame($site->mediaPrefix().'/videos/drive.mp4', $hero->data['video_key']);
        Storage::disk('r2')->assertExists($site->mediaPrefix().'/videos/drive.mp4');

        // Again: the same page is updated, not a second one made.
        $again = $this->importer()->apply($this->package($this->manifest()));
        $this->assertTrue($again->written);
        $this->assertSame(1, $site->pages()->where('slug', 'tours/etosha')->count());
        $this->assertSame('update', $this->importer()->plan($this->package($this->manifest()))->pages[0]['action']);
    }

    public function test_a_subpage_may_not_take_an_address_the_site_answers_itself(): void
    {
        $manifest = $this->manifest();
        $manifest['pages'][] = ['slug' => 'card', 'title' => 'Card', 'blocks' => []];
        $manifest['pages'][] = ['slug' => 'Bad Slug', 'title' => 'Bad', 'blocks' => []];

        $errors = implode("\n", $this->importer()->plan($this->package($manifest))->errors);

        $this->assertStringContainsString('/card is taken', $errors);
        $this->assertStringContainsString('lowercase letters', $errors);
    }

    public function test_every_contact_form_on_a_site_asks_for_the_same_thing(): void
    {
        $manifest = $this->manifest();
        $manifest['blocks'][] = ['type' => 'enquiry', 'data' => ['form_type' => 'contact']];

        $errors = implode("\n", $this->importer()->plan($this->package($manifest))->errors);

        $this->assertStringContainsString('one kind of form', $errors);
    }

    public function test_the_enterprise_page_carries_the_showcase_and_the_standard_one_does_not(): void
    {
        $site = $this->siteWithHero(SiteEdition::Enterprise, ['headline' => 'Etosha', 'video_key' => 'sites/x/videos/loop.mp4', 'video_layout' => 'card']);
        $html = $this->get('/_sites/'.$site->slug)->assertOk()->getContent();

        $this->assertStringContainsString('<body id="top" class="sc">', $html);
        $this->assertStringContainsString('hero--card', $html);
        $this->assertStringContainsString('class="hero__scroll"', $html);
        // The video waits for the script: no src in the markup.
        $this->assertMatchesRegularExpression('#<video class="hero__video" data-src="[^"]+loop\.mp4"#', $html);
        $this->assertDoesNotMatchRegularExpression('#<video class="hero__video"[^>]* src=#', $html);

        $standard = $this->siteWithHero(SiteEdition::Standard, ['headline' => 'Etosha']);
        $html = $this->get('/_sites/'.$standard->slug)->assertOk()->getContent();

        $this->assertStringContainsString('<body id="top">', $html);
        $this->assertStringNotContainsString('hero__scroll', $html);
    }

    public function test_a_subpage_links_home_and_a_card_links_to_it(): void
    {
        $site = $this->siteWithHero(SiteEdition::Enterprise, ['headline' => 'Home']);
        $home = $site->pages()->where('is_home', true)->sole();

        SiteBlock::create(['site_page_id' => $home->id, 'type' => 'offers', 'sort' => 1, 'data' => [
            'items' => [['title' => 'Etosha tour', 'page_slug' => 'tours/etosha']],
        ]]);

        $tour = SitePage::create(['site_id' => $site->id, 'slug' => 'tours/etosha', 'locale' => 'en', 'title' => 'Etosha tour', 'nav_label' => 'Etosha', 'show_in_nav' => false, 'is_home' => false, 'sort' => 1]);
        SiteBlock::create(['site_page_id' => $tour->id, 'type' => 'itinerary', 'sort' => 0, 'data' => [
            'heading' => 'Day by day', 'items' => [['day' => 'Day 1', 'title' => 'Okaukuejo']],
        ]]);

        $homeHtml = $this->get('/_sites/'.$site->slug)->assertOk()->getContent();
        $this->assertStringContainsString('href="'.$site->pageUrl('tours/etosha').'"', $homeHtml);
        // Hidden from the menu: the only link to it is the card.
        $this->assertStringNotContainsString('>Etosha</a>', $homeHtml);

        $tourHtml = $this->get('/_sites/'.$site->slug.'/tours/etosha')->assertOk()->getContent();
        $this->assertStringContainsString('Okaukuejo', $tourHtml);
        $this->assertMatchesRegularExpression('#href="'.preg_quote($site->pageUrl(), '#').'"\s*>Home</a>#', $tourHtml);
        // Its own sections are anchors on this page.
        $this->assertStringContainsString('href="#s1"', $tourHtml);
    }

    public function test_the_route_map_draws_the_country_and_the_road(): void
    {
        $site = $this->siteWithHero(SiteEdition::Enterprise, ['headline' => 'Home']);
        $home = $site->pages()->where('is_home', true)->sole();

        SiteBlock::create(['site_page_id' => $home->id, 'type' => 'route_map', 'sort' => 1, 'data' => [
            'heading' => 'Where the road goes',
            'items' => [
                ['name' => 'Windhoek', 'lat' => -22.56, 'lng' => 17.08],
                ['name' => 'Sossusvlei', 'label' => 'Days 2-3', 'lat' => -24.73, 'lng' => 15.3],
                ['name' => 'Windhoek', 'lat' => -22.56, 'lng' => 17.08],
            ],
        ]]);

        $html = $this->get('/_sites/'.$site->slug)->assertOk()->getContent();

        $this->assertStringContainsString('class="map__land"', $html);
        $this->assertMatchesRegularExpression('#<path id="rm-road" d="M[0-9.]+ [0-9.]+Q#', $html);
        // A stop the road returns to is labelled and listed once.
        $this->assertSame(1, substr_count($html, '>Windhoek</text>'));
        $this->assertStringContainsString('<span>Days 2-3</span>', $html);
    }

    public function test_a_photograph_used_only_as_a_background_is_still_loaded(): void
    {
        $site = $this->siteWithHero(SiteEdition::Enterprise, ['headline' => 'Home']);
        $home = $site->pages()->where('is_home', true)->sole();
        $canyon = SiteImage::create(['site_id' => $site->id, 'key' => 'sites/'.$site->slug.'/canyon.jpg', 'sort' => 0]);

        SiteBlock::create(['site_page_id' => $home->id, 'type' => 'faq', 'sort' => 1, 'data' => [
            'heading' => 'Good to know', 'background_image_id' => $canyon->id,
            'items' => [['question' => 'Q?', 'answer' => 'A.']],
        ]]);

        $html = $this->get('/_sites/'.$site->slug)->assertOk()->getContent();

        $this->assertStringContainsString('section--photo', $html);
        $this->assertMatchesRegularExpression('#class="section__photo"[^>]*>\s*<img src="[^"]*canyon#', $html);
    }

    public function test_a_site_with_a_logo_carries_its_own_tab_icon(): void
    {
        $site = $this->siteWithHero(SiteEdition::Standard, ['headline' => 'Home']);
        $site->update(['logo_key' => 'sites/'.$site->slug.'/logo.png']);

        $html = $this->get('/_sites/'.$site->slug)->assertOk()->getContent();

        // Without this the browser asks the host for NamibWay's own favicon.
        $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]*logo[^"]*"#', $html);
        $this->assertStringContainsString('apple-touch-icon', $html);
    }

    public function test_the_numbers_band_shows_the_real_numbers_before_any_script(): void
    {
        $site = $this->siteWithHero(SiteEdition::Standard, ['headline' => 'Home']);
        $home = $site->pages()->where('is_home', true)->sole();

        SiteBlock::create(['site_page_id' => $home->id, 'type' => 'stats', 'sort' => 1, 'data' => [
            'items' => [['value' => 2335, 'unit' => 'km', 'label' => 'The full circuit']],
            'ticker' => ['Etosha', 'Sossusvlei'],
        ]]);

        $html = $this->get('/_sites/'.$site->slug)->assertOk()->getContent();

        $this->assertStringContainsString('<span data-count="2335">2,335</span><small>km</small>', $html);
        $this->assertStringContainsString('<span>Sossusvlei</span>', $html);
    }

    /**
     * @param  array<string, mixed>  $hero
     */
    private function siteWithHero(SiteEdition $edition, array $hero): Site
    {
        $site = Site::factory()->published()->create(['edition' => $edition]);
        $page = SitePage::factory()->create(['site_id' => $site->id, 'is_home' => true, 'slug' => '']);
        SiteBlock::create(['site_page_id' => $page->id, 'type' => 'hero', 'sort' => 0, 'data' => $hero]);

        return $site;
    }

    private function importer(): SitePackageImporter
    {
        return app(SitePackageImporter::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'version' => 1,
            'partner' => ['name' => 'Epima Tours & Safaris', 'email' => 'info@epima.example'],
            'listing' => ['type' => 'vehicle', 'vehicle_category' => 'guided_tour'],
            'site' => ['edition' => 'enterprise'],
            'blocks' => [
                ['type' => 'hero', 'data' => ['image' => 'lion.jpg', 'video' => 'drive.mp4', 'video_layout' => 'card', 'headline' => 'Etosha']],
                ['type' => 'enquiry', 'data' => ['form_type' => 'tour_request']],
            ],
            'pages' => [[
                'slug' => 'tours/etosha',
                'title' => 'Etosha tour',
                'nav_label' => 'Etosha',
                'show_in_nav' => false,
                'blocks' => [
                    ['type' => 'hero', 'data' => ['image' => 'lion.jpg', 'headline' => 'Etosha tour']],
                    ['type' => 'itinerary', 'data' => ['items' => [['day' => 'Day 1', 'title' => 'Okaukuejo']]]],
                    ['type' => 'enquiry', 'data' => ['form_type' => 'tour_request', 'listing_slug' => 'etosha']],
                ],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function package(array $manifest): SitePackage
    {
        if (isset($this->zip) && is_file($this->zip)) {
            unlink($this->zip);
        }

        $this->zip = tempnam(sys_get_temp_dir(), 'pkg').'.zip';
        $zip = new ZipArchive;
        $zip->open($this->zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('site.json', (string) json_encode($manifest));
        $zip->addFromString('media/lion.jpg', 'jpg-bytes');
        $zip->addFromString('media/drive.mp4', 'mp4-bytes');
        $zip->close();

        return SitePackage::open($this->zip);
    }
}
