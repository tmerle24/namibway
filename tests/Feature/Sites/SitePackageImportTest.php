<?php

namespace Tests\Feature\Sites;

use App\Enums\ListingType;
use App\Filament\Pages\SitePackageImport;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Models\User;
use App\Sites\Import\SitePackage;
use App\Sites\Import\SitePackageImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * One ZIP → partner, listing, website. Check never writes; Import creates what
 * is missing, updates what exists, and deletes nothing.
 */
class SitePackageImportTest extends TestCase
{
    use RefreshDatabase;

    private string $zip;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if (isset($this->zip) && is_file($this->zip)) {
            unlink($this->zip);
        }

        parent::tearDown();
    }

    public function test_a_package_creates_partner_listing_and_website(): void
    {
        $plan = $this->importer()->apply($this->package($this->manifest()));

        $this->assertTrue($plan->written, implode(' ', $plan->errors));

        $partner = Partner::where('name', 'Epima Tours & Safaris')->sole();
        $listing = Listing::where('partner_id', $partner->id)->sole();
        $site = Site::findOrFail($plan->siteId);

        // The listing starts from the partner's facts.
        $this->assertSame(ListingType::Vehicle, $listing->type);
        $this->assertSame('info@epima.example', $listing->contact_email);
        $this->assertFalse((bool) $listing->is_published);

        $this->assertSame($listing->id, $site->source_listing_id);
        $this->assertSame('Tours in Etosha', $site->pages()->where('is_home', true)->sole()->title);

        $hero = $this->block($site, 'hero');
        $lion = $site->images()->where('key', $site->mediaPrefix().'/lion.jpg')->sole();
        $this->assertSame($lion->id, $hero->data['image_id']);
        $this->assertSame('A lion at the waterhole', $lion->alt);

        $offers = $this->block($site, 'offers');
        $this->assertSame($lion->id, $offers->data['items'][0]['image_id']);

        $video = $this->block($site, 'video');
        $this->assertSame($site->mediaPrefix().'/videos/drive.mp4', $video->data['items'][0]['key']);

        Storage::disk('r2')->assertExists($site->mediaPrefix().'/lion.jpg');
        Storage::disk('r2')->assertExists($site->mediaPrefix().'/videos/drive.mp4');
    }

    public function test_a_package_brings_the_logo_and_how_it_sits(): void
    {
        $manifest = $this->manifest();
        $manifest['site'] += ['logo' => 'lion.jpg', 'logo_hero_height' => 120, 'logo_compact_height' => 52, 'logo_shadow' => 'shadow',
            'action_buttons' => ['enquiry' => ['places' => ['menu.desktop', 'nowhere.at-all']]]];

        $plan = $this->importer()->apply($this->package($manifest));
        $site = Site::findOrFail($plan->siteId);

        $this->assertSame($site->mediaPrefix().'/lion.jpg', $site->logo_key);
        $this->assertSame(120, $site->logo_hero_height);
        $this->assertSame('shadow', $site->logo_shadow);
        // Normalised: an unknown place is dropped, the other buttons keep their defaults.
        $this->assertSame(['menu.desktop'], $site->action_buttons['enquiry']['places']);
        $this->assertArrayHasKey('whatsapp', $site->action_buttons);

        $manifest['site']['logo_hero_height'] = 900;
        $this->assertNotEmpty($this->importer()->plan($this->package($manifest))->errors);
    }

    public function test_checking_writes_nothing(): void
    {
        $plan = $this->importer()->plan($this->package($this->manifest()));

        $this->assertFalse($plan->hasErrors(), implode(' ', $plan->errors));
        $this->assertSame('create', $plan->site['action']);
        $this->assertSame(2, $plan->imagesNew);
        $this->assertSame(1, $plan->videos);
        $this->assertSame(0, Partner::count());
        $this->assertSame(0, Site::count());
        Storage::disk('r2')->assertMissing('sites');
    }

    public function test_an_existing_site_is_updated_and_nothing_is_lost(): void
    {
        $partner = Partner::create(['name' => 'Epima Tours & Safaris', 'email' => 'old@epima.example']);
        $site = Site::factory()->create(['partner_id' => $partner->id, 'slug' => 'epima-tours-safaris', 'name' => 'Epima Tours & Safaris']);
        $page = SitePage::factory()->create(['site_id' => $site->id, 'is_home' => true, 'slug' => '']);
        SiteBlock::create(['site_page_id' => $page->id, 'type' => 'contact', 'data' => ['heading' => 'Call us'], 'sort' => 0]);
        SiteBlock::create(['site_page_id' => $page->id, 'type' => 'hero', 'data' => ['headline' => 'Old'], 'sort' => 1]);

        $manifest = $this->manifest();
        unset($manifest['listing']);
        $manifest['site']['slug'] = 'epima-tours-safaris';

        $plan = $this->importer()->plan($this->package($manifest));
        $this->assertSame('update', $plan->partner['action']);
        $this->assertContains('email', array_column($plan->partner['changes'], 'field'));

        $this->importer()->apply($this->package($manifest));

        $this->assertSame(1, Site::count());
        $this->assertSame('info@epima.example', $partner->refresh()->email);
        $this->assertSame('Etosha, with a guide who knows it', $this->block($site, 'hero')->data['headline']);
        // Not in the package: kept, and moved below the bands that are.
        $contact = $this->block($site, 'contact');
        $this->assertSame('Call us', $contact->data['heading']);
        $this->assertGreaterThan($this->block($site, 'video')->sort, $contact->sort);
    }

    public function test_importing_twice_adds_no_duplicate_pictures(): void
    {
        $this->importer()->apply($this->package($this->manifest()));
        $plan = $this->importer()->apply($this->package($this->manifest() + []));

        $site = Site::findOrFail($plan->siteId);
        $this->assertSame(1, Site::count());
        $this->assertSame(2, $site->images()->count());
        $this->assertSame(1, Listing::count());
    }

    public function test_a_package_with_problems_writes_nothing(): void
    {
        $manifest = $this->manifest();
        $manifest['blocks'][] = ['type' => 'weather_widget', 'data' => []];
        $manifest['blocks'][0]['data']['image'] = 'missing.jpg';

        $plan = $this->importer()->apply($this->package($manifest));

        $this->assertFalse($plan->written);
        $this->assertNotEmpty(array_filter($plan->errors, fn ($e) => str_contains($e, 'weather_widget')));
        $this->assertNotEmpty(array_filter($plan->errors, fn ($e) => str_contains($e, 'missing.jpg')));
        $this->assertSame(0, Partner::count());
    }

    public function test_a_zip_without_site_json_is_refused(): void
    {
        $plan = $this->importer()->plan($this->package(null));

        $this->assertContains('No site.json in the ZIP.', $plan->errors);
    }

    public function test_the_admin_page_checks_and_imports(): void
    {
        $this->package($this->manifest());
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/site-package-import')->assertOk()->assertSee('Import a website package');

        Livewire::actingAs($admin)
            ->test(SitePackageImport::class)
            ->set('data.package', [UploadedFile::fake()->createWithContent('epima.zip', (string) file_get_contents($this->zip))])
            ->call('check')
            ->assertSet('preview.images_new', 2)
            ->call('import')
            ->assertSet('preview.written', true);

        $this->assertSame(1, Site::count());
    }

    private function importer(): SitePackageImporter
    {
        return app(SitePackageImporter::class);
    }

    private function block(Site $site, string $type): SiteBlock
    {
        return $site->pages()->where('is_home', true)->sole()->blocks()->where('type', $type)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'version' => 1,
            'partner' => ['name' => 'Epima Tours & Safaris', 'email' => 'info@epima.example', 'phone' => '+264 81 000 0000', 'address' => 'Otjiwarongo'],
            'listing' => ['type' => 'vehicle', 'vehicle_category' => 'guided_tour'],
            'site' => ['accent' => 'copper', 'title' => 'Tours in Etosha', 'whatsapp' => '+264 81 000 0000'],
            'images' => [['file' => 'lion.jpg', 'alt' => 'A lion at the waterhole']],
            'blocks' => [
                ['type' => 'hero', 'data' => ['image' => 'lion.jpg', 'headline' => 'Etosha, with a guide who knows it']],
                ['type' => 'offers', 'data' => ['heading' => 'Tours', 'items' => [['title' => 'Full day', 'image' => 'lion.jpg']]]],
                ['type' => 'gallery', 'data' => ['images' => ['lion.jpg', 'Zebras.JPG']]],
                ['type' => 'video', 'data' => ['items' => [['video' => 'drive.mp4', 'poster' => 'lion.jpg']]]],
                ['type' => 'footer'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     */
    private function package(?array $manifest): SitePackage
    {
        if (isset($this->zip) && is_file($this->zip)) {
            unlink($this->zip);
        }

        $this->zip = tempnam(sys_get_temp_dir(), 'pkg').'.zip';
        $zip = new ZipArchive;
        $zip->open($this->zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($manifest !== null) {
            $zip->addFromString('epima/site.json', (string) json_encode($manifest));
        }

        $zip->addFromString('epima/media/lion.jpg', 'jpg-bytes');
        $zip->addFromString('epima/media/Zebras.JPG', 'jpg-bytes');
        $zip->addFromString('epima/media/drive.mp4', 'mp4-bytes');
        $zip->addFromString('__MACOSX/epima/._lion.jpg', 'junk');
        $zip->close();

        return SitePackage::open($this->zip);
    }
}
