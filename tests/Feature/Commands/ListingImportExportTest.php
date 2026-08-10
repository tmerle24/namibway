<?php

namespace Tests\Feature\Commands;

use App\Enums\ListingType;
use App\Models\City;
use App\Models\Listing;
use App\Models\Partner;
use App\Services\ImportExport\ListingExporter;
use App\Services\ImportExport\ListingImporter;
use App\Services\ImportExport\ListingSheet;
use App\Services\ImportExport\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bulk capture round trip: export → edit in Excel → import back.
 *
 * The rules under test are the ones that keep a spreadsheet from quietly
 * destroying the catalog (see CLAUDE.md's data-loss lesson): an untouched
 * export must import as a no-op, an empty cell must never blank a field, and a
 * row without an id must never silently overwrite an existing listing.
 */
class ListingImportExportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'listings').'.xlsx';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->csvPath()] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function csvPath(): string
    {
        return $this->path.'.csv';
    }

    private function listing(array $overrides = []): Listing
    {
        $partner = Partner::create(['name' => 'Test Partner '.uniqid()]);

        return Listing::factory()->create(array_merge([
            'partner_id' => $partner->id,
            'name' => 'Okonjima Bush Camp',
            'type' => ListingType::Accommodation,
            'price_from' => 1250.00,
            'price_currency' => 'NAD',
            'is_published' => true,
        ], $overrides));
    }

    /** Writes a CSV with the given headers/rows — the same reader path an uploaded file takes. */
    private function writeCsv(array $headers, array $rows): string
    {
        $handle = fopen($this->csvPath(), 'w');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $this->csvPath();
    }

    private function importer(): ListingImporter
    {
        return app(ListingImporter::class);
    }

    public function test_export_writes_the_sheet_headers_and_one_row_per_listing(): void
    {
        $city = City::query()->firstOrFail();
        $listing = $this->listing(['city_id' => $city->id, 'latitude' => -20.8, 'longitude' => 16.7]);

        app(ListingExporter::class)->export(Listing::query(), $this->path);

        $file = app(SpreadsheetReader::class)->read($this->path);

        $this->assertSame(
            array_map(fn ($column) => $column->header, ListingSheet::columns()),
            $file['headers'],
        );
        $this->assertCount(1, $file['rows']);

        $cells = array_combine($file['headers'], $file['rows'][0]['cells']);
        $this->assertSame((string) $listing->id, $cells['id']);
        $this->assertSame('Okonjima Bush Camp', $cells['name']);
        $this->assertSame('accommodation', $cells['type']);
        $this->assertSame($city->name, $cells['city']);
        $this->assertSame('-20.800000, 16.700000', $cells['coordinates']);
        $this->assertSame('yes', $cells['published']);
    }

    public function test_reimporting_an_untouched_export_changes_nothing(): void
    {
        $city = City::query()->firstOrFail();
        $this->listing([
            'city_id' => $city->id,
            'latitude' => -20.8,
            'longitude' => 16.7,
            'description' => 'Cheetahs, and children <12 free.',
            'short_description' => 'A camp.',
        ]);

        app(ListingExporter::class)->export(Listing::query(), $this->path);

        $plan = $this->importer()->plan($this->path);

        $this->assertFalse($plan->hasErrors(), implode(' / ', $plan->invalidRows()[0]->errors ?? []));
        $this->assertSame([], $plan->applicableRows());
        $this->assertSame(1, $plan->unchangedCount());
    }

    public function test_an_empty_cell_leaves_the_existing_value_alone(): void
    {
        $listing = $this->listing(['phone' => '+264 61 123456']);

        $file = $this->writeCsv(['id', 'price_from', 'phone'], [[$listing->id, '1800', '']]);

        $plan = $this->importer()->plan($file);
        $this->importer()->apply($plan);

        $listing->refresh();
        $this->assertSame('1800.00', $listing->price_from);
        $this->assertSame('+264 61 123456', $listing->phone);
        $this->assertCount(1, $plan->applicableRows()[0]->changes);
    }

    public function test_a_clear_marker_empties_a_field(): void
    {
        $listing = $this->listing(['phone' => '+264 61 123456']);

        $file = $this->writeCsv(['id', 'phone'], [[$listing->id, 'CLEAR']]);

        $this->importer()->apply($this->importer()->plan($file));

        $this->assertNull($listing->refresh()->phone);
    }

    public function test_a_row_without_an_id_that_matches_an_existing_listing_is_an_error(): void
    {
        $listing = $this->listing();

        $file = $this->writeCsv(['name', 'type', 'price_from'], [['Okonjima Bush Camp', 'accommodation', '9999']]);

        $plan = $this->importer()->plan($file);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString((string) $listing->id, $plan->invalidRows()[0]->errors[0]);
        $this->assertSame(0, $this->importer()->apply($plan));
        $this->assertSame('1250.00', $listing->refresh()->price_from);
    }

    public function test_a_new_row_is_created_unpublished_with_its_city_resolved_by_name(): void
    {
        $city = City::query()->firstOrFail();

        $file = $this->writeCsv(
            ['name', 'type', 'city', 'price_from', 'coordinates'],
            [['Desert Whisper Lodge', 'accommodation', $city->name, '1.250,50', '-24,1 15,3']],
        );

        $plan = $this->importer()->plan($file);
        $this->assertFalse($plan->hasErrors(), implode(' / ', $plan->invalidRows()[0]->errors ?? []));
        $this->assertSame(1, $this->importer()->apply($plan));

        $listing = Listing::where('slug', 'desert-whisper-lodge')->firstOrFail();
        $this->assertSame($city->id, $listing->city_id);
        $this->assertSame('1250.50', $listing->price_from);
        $this->assertSame(-24.1, (float) $listing->latitude);
        $this->assertSame(15.3, (float) $listing->longitude);
        $this->assertFalse($listing->is_published);
        $this->assertSame('manual', $listing->data_source);
    }

    public function test_coordinates_can_be_pasted_as_a_google_maps_link(): void
    {
        $listing = $this->listing();

        $file = $this->writeCsv(
            ['id', 'coordinates'],
            [[$listing->id, 'https://www.google.com/maps/@-22.559700,17.083200,15z']],
        );

        $this->importer()->apply($this->importer()->plan($file));

        $listing->refresh();
        $this->assertSame(-22.5597, (float) $listing->latitude);
        $this->assertSame(17.0832, (float) $listing->longitude);
    }

    public function test_an_unknown_city_and_an_unknown_id_are_reported_without_writing(): void
    {
        $file = $this->writeCsv(
            ['id', 'name', 'type', 'city'],
            [
                ['', 'New Lodge', 'accommodation', 'Atlantis'],
                ['999999', 'Does not exist', '', ''],
            ],
        );

        $plan = $this->importer()->plan($file);

        $this->assertCount(2, $plan->invalidRows());
        $this->assertStringContainsString('Atlantis', $plan->invalidRows()[0]->errors[0]);
        $this->assertStringContainsString('999999', $plan->invalidRows()[1]->errors[0]);
        $this->assertSame(0, Listing::count());
    }

    public function test_a_dry_run_via_the_command_writes_nothing(): void
    {
        $listing = $this->listing();
        $file = $this->writeCsv(['id', 'price_from'], [[$listing->id, '4000']]);

        $this->artisan('namibway:import-listings', ['file' => $file, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame('1250.00', $listing->refresh()->price_from);
    }

    public function test_the_command_refuses_a_file_with_errors_but_imports_with_skip_invalid(): void
    {
        $listing = $this->listing();
        $file = $this->writeCsv(
            ['id', 'price_from'],
            [
                [$listing->id, '4000'],
                ['999999', '1'],
            ],
        );

        $this->artisan('namibway:import-listings', ['file' => $file, '--force' => true])
            ->assertExitCode(1);
        $this->assertSame('1250.00', $listing->refresh()->price_from);

        $this->artisan('namibway:import-listings', ['file' => $file, '--force' => true, '--skip-invalid' => true])
            ->assertExitCode(0);
        $this->assertSame('4000.00', $listing->refresh()->price_from);
    }

    public function test_a_file_without_usable_headers_is_rejected(): void
    {
        $file = $this->writeCsv(['foo', 'bar'], [['a', 'b']]);

        $plan = $this->importer()->plan($file);

        $this->assertTrue($plan->isBlocked());
        $this->assertSame(['foo', 'bar'], $plan->ignoredHeaders);
    }
}
