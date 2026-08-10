<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ListingImport;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * The admin-side of the bulk capture flow: the content team uploads a workbook,
 * sees what it would do, and only then imports. "Check" must never write, and
 * "Import" must refuse a file that has errors unless it's told otherwise.
 */
class ListingImportPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function listing(): Listing
    {
        $partner = Partner::create(['name' => 'Test Partner '.uniqid()]);

        return Listing::factory()->create([
            'partner_id' => $partner->id,
            'name' => 'Okonjima Bush Camp',
            'slug' => 'okonjima-bush-camp',
            'price_from' => 1250.00,
        ]);
    }

    /** @param list<array<int, string|int|null>> $rows */
    private function upload(array $headers, array $rows): File
    {
        $lines = array_map(
            static fn (array $row): string => implode(',', $row),
            array_merge([$headers], $rows),
        );

        return UploadedFile::fake()->createWithContent('listings.csv', implode("\n", $lines)."\n");
    }

    /** @param array<string, string> $files path inside the archive => contents */
    private function uploadZip(array $files): File
    {
        $path = tempnam(sys_get_temp_dir(), 'photos').'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('photos.zip', $bytes);
    }

    public function test_the_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/listing-import')
            ->assertOk()
            ->assertSee('Import listings');
    }

    public function test_the_guide_renders_the_real_column_definitions(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/bulk-capture-guide')
            ->assertOk()
            ->assertSee('photo_folder')
            ->assertSee('rate_per_night')
            ->assertSee('Only upload photos we are allowed to publish');
    }

    public function test_photos_are_imported_from_an_uploaded_zip(): void
    {
        Storage::fake('local');
        Storage::fake('r2');
        $listing = $this->listing();

        Livewire::actingAs($this->admin())
            ->test(ListingImport::class)
            ->set('data.file', [$this->upload(['id', 'photo_folder'], [[$listing->id, 'Okonjima Bush Camp']])])
            ->set('data.photos', [$this->uploadZip(['Okonjima Bush Camp/cover.jpg' => 'cover-bytes'])])
            ->call('check')
            ->assertSet('preview.photos', 1)
            ->call('import');

        $listing->refresh();
        $this->assertStringContainsString('listings/okonjima-bush-camp/cover-', (string) $listing->image);
        Storage::disk('r2')->assertExists((string) $listing->image);
    }

    public function test_the_template_can_be_downloaded_from_the_page(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)
            ->test(ListingImport::class)
            ->callAction('downloadTemplate');

        $url = (string) data_get($component->effects, 'redirect');

        $this->actingAs($admin)->get($url)->assertOk()->assertDownload('listings-template.xlsx');
    }

    public function test_checking_a_file_previews_the_changes_without_writing(): void
    {
        Storage::fake('local');
        $listing = $this->listing();

        Livewire::actingAs($this->admin())
            ->test(ListingImport::class)
            ->set('data.file', [$this->upload(['id', 'price_from'], [[$listing->id, 4000]])])
            ->call('check')
            ->assertSet('preview.updated', 1)
            ->assertSet('preview.changes', 1)
            ->assertSet('preview.invalid', 0);

        $this->assertSame('1250.00', $listing->refresh()->price_from);
    }

    public function test_importing_writes_the_changes(): void
    {
        Storage::fake('local');
        $listing = $this->listing();

        Livewire::actingAs($this->admin())
            ->test(ListingImport::class)
            ->set('data.file', [$this->upload(['id', 'price_from'], [[$listing->id, 4000]])])
            ->call('import');

        $this->assertSame('4000.00', $listing->refresh()->price_from);
    }

    public function test_a_file_with_errors_is_refused_until_skip_invalid_is_switched_on(): void
    {
        Storage::fake('local');
        $listing = $this->listing();

        $component = Livewire::actingAs($this->admin())
            ->test(ListingImport::class)
            ->set('data.file', [$this->upload(['id', 'price_from'], [[$listing->id, 4000], [999999, 1]])])
            ->call('import')
            ->assertSet('preview.invalid', 1);

        $this->assertSame('1250.00', $listing->refresh()->price_from);

        $component->set('data.skip_invalid', true)->call('import');

        $this->assertSame('4000.00', $listing->refresh()->price_from);
    }
}
