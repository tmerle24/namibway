<?php

namespace Tests\Feature\Commands;

use App\Enums\ContentSource;
use App\Enums\ListingType;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\Partner;
use App\Services\ImportExport\BookableUnitSheet;
use App\Services\ImportExport\ListingExporter;
use App\Services\ImportExport\ListingImporter;
use App\Services\ImportExport\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;
use ZipArchive;

/**
 * The two things a listing needs beyond its text: photos, which arrive as a ZIP
 * of one folder per listing, and room types, which arrive on their own sheet of
 * the same workbook.
 *
 * Both follow the rules the rest of the import follows — an empty cell changes
 * nothing, and nothing is deleted — with one deliberate exception under test
 * here: naming a photo folder REPLACES that listing's photos, because a set of
 * photos with two cover images is not a thing.
 */
class ListingPhotoRoomImportTest extends TestCase
{
    use RefreshDatabase;

    private string $workbook;

    private string $zip;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');

        $this->workbook = tempnam(sys_get_temp_dir(), 'workbook').'.xlsx';
        $this->zip = tempnam(sys_get_temp_dir(), 'photos').'.zip';
    }

    protected function tearDown(): void
    {
        foreach ([$this->workbook, $this->zip] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function listing(array $overrides = []): Listing
    {
        $partner = Partner::create(['name' => 'Test Partner '.uniqid()]);

        return Listing::factory()->create(array_merge([
            'partner_id' => $partner->id,
            'name' => 'Okonjima Bush Camp',
            'slug' => 'okonjima-bush-camp',
            'type' => ListingType::Accommodation,
            'image' => null,
            'gallery' => null,
        ], $overrides));
    }

    /** @param array<string, string> $files path inside the archive => contents */
    private function makeZip(array $files): string
    {
        $zip = new ZipArchive;
        $zip->open($this->zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }

        $zip->close();

        return $this->zip;
    }

    /** @param array<string, list<array<int, string|int|null>>> $sheets */
    private function makeWorkbook(array $sheets): string
    {
        $writer = new Writer;
        $writer->openToFile($this->workbook);
        $first = true;

        foreach ($sheets as $name => $rows) {
            if (! $first) {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $writer->getCurrentSheet()->setName($name);
            $first = false;

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        return $this->workbook;
    }

    private function importer(): ListingImporter
    {
        return app(ListingImporter::class);
    }

    public function test_a_photo_folder_becomes_the_hero_image_and_the_gallery(): void
    {
        $listing = $this->listing();

        $zip = $this->makeZip([
            'Photos/Okonjima Bush Camp/pool.jpg' => 'pool-bytes',
            'Photos/Okonjima Bush Camp/cover.jpg' => 'cover-bytes',
            'Photos/Okonjima Bush Camp/room.png' => 'room-bytes',
            'Photos/Okonjima Bush Camp/notes.txt' => 'ignored',
            '__MACOSX/Photos/Okonjima Bush Camp/._cover.jpg' => 'resource fork',
        ]);

        $workbook = $this->makeWorkbook(['Listings' => [
            ['id', 'photo_folder'],
            [$listing->id, 'Okonjima Bush Camp'],
        ]]);

        $plan = $this->importer()->plan($workbook, $zip);

        $this->assertFalse($plan->hasErrors(), implode(' / ', $plan->invalidRows()[0]->errors ?? []));
        $this->assertSame(1, $plan->photoCount());
        $this->assertSame(1, $this->importer()->apply($plan, $zip));

        $listing->refresh();

        // "cover" wins regardless of where it sits alphabetically; the .txt is ignored.
        $this->assertStringContainsString('listings/okonjima-bush-camp/cover-', (string) $listing->image);
        $this->assertCount(2, $listing->gallery ?? []);
        $this->assertSame(ContentSource::Partner, $listing->photos_source);
        $this->assertNotNull($listing->photos_approved_at);

        Storage::disk('r2')->assertExists((string) $listing->image);

        foreach ($listing->gallery ?? [] as $key) {
            Storage::disk('r2')->assertExists($key);
        }
    }

    public function test_an_empty_photo_folder_cell_leaves_existing_photos_alone(): void
    {
        $listing = $this->listing(['image' => 'listings/old.jpg', 'gallery' => ['listings/old-2.jpg']]);

        $workbook = $this->makeWorkbook(['Listings' => [
            ['id', 'photo_folder', 'phone'],
            [$listing->id, '', '+264 61 000000'],
        ]]);

        $this->importer()->apply($this->importer()->plan($workbook, $this->makeZip([])));

        $listing->refresh();
        $this->assertSame('listings/old.jpg', $listing->image);
        $this->assertSame(['listings/old-2.jpg'], $listing->gallery);
    }

    public function test_a_folder_that_is_not_in_the_zip_is_an_error_and_nothing_is_written(): void
    {
        $listing = $this->listing();

        $zip = $this->makeZip(['Photos/Some Other Lodge/cover.jpg' => 'bytes']);
        $workbook = $this->makeWorkbook(['Listings' => [
            ['id', 'photo_folder'],
            [$listing->id, 'Okonjima Bush Camp'],
        ]]);

        $plan = $this->importer()->plan($workbook, $zip);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('Some Other Lodge', $plan->invalidRows()[0]->errors[0]);
        $this->assertSame(0, $this->importer()->apply($plan, $zip));
        $this->assertNull($listing->refresh()->image);
    }

    public function test_a_photo_folder_without_a_zip_is_an_error(): void
    {
        $listing = $this->listing();

        $workbook = $this->makeWorkbook(['Listings' => [
            ['id', 'photo_folder'],
            [$listing->id, 'Okonjima Bush Camp'],
        ]]);

        $plan = $this->importer()->plan($workbook);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('no photo ZIP', $plan->invalidRows()[0]->errors[0]);
    }

    public function test_bookable_units_are_created_and_then_updated_by_listing_and_code(): void
    {
        $listing = $this->listing();

        $workbook = $this->makeWorkbook([
            'Listings' => [['id'], [$listing->id]],
            'RoomTypes' => [
                ['listing_id', 'listing', 'code', 'name', 'total_units', 'rate_per_night', 'max_adults'],
                [$listing->id, 'Okonjima Bush Camp', 'STD', 'Standard Chalet', 6, '1450', 2],
            ],
        ]);

        $plan = $this->importer()->plan($workbook);

        $this->assertFalse($plan->hasErrors(), implode(' / ', $plan->invalidRoomRows()[0]->errors ?? []));
        $this->assertSame(1, $plan->roomNewCount());
        $this->assertSame(1, $this->importer()->apply($plan));

        $room = BookableUnit::where('listing_id', $listing->id)->where('code', 'STD')->firstOrFail();
        $this->assertSame('Standard Chalet', $room->name);
        $this->assertSame(6, $room->total_units);
        $this->assertSame('1450.00', $room->rate_per_night);

        // Same file again, one value changed: the room is updated, not duplicated.
        $workbook = $this->makeWorkbook([
            'Listings' => [['id'], [$listing->id]],
            'RoomTypes' => [
                ['listing_id', 'code', 'name', 'total_units', 'rate_per_night'],
                [$listing->id, 'STD', 'Standard Chalet', 6, '1600'],
            ],
        ]);

        $plan = $this->importer()->plan($workbook);
        $this->assertSame(0, $plan->roomNewCount());
        $this->assertSame(1, $plan->roomUpdateCount());
        $this->importer()->apply($plan);

        $this->assertSame(1, BookableUnit::where('listing_id', $listing->id)->count());
        $this->assertSame('1600.00', $room->refresh()->rate_per_night);
    }

    public function test_a_room_can_reference_a_listing_the_same_import_creates(): void
    {
        $workbook = $this->makeWorkbook([
            'Listings' => [
                ['name', 'type'],
                ['Desert Whisper Lodge', 'accommodation'],
            ],
            'RoomTypes' => [
                ['listing_id', 'listing', 'code', 'name', 'total_units', 'rate_per_night'],
                ['', 'Desert Whisper Lodge', 'SUITE', 'Desert Suite', 3, '3200'],
            ],
        ]);

        $plan = $this->importer()->plan($workbook);
        $this->assertFalse($plan->hasErrors());
        $this->assertSame(2, $this->importer()->apply($plan));

        $listing = Listing::where('slug', 'desert-whisper-lodge')->firstOrFail();
        $room = BookableUnit::where('listing_id', $listing->id)->firstOrFail();
        $this->assertSame('SUITE', $room->code);
        $this->assertSame(3, $room->total_units);
    }

    public function test_a_room_for_an_unknown_listing_is_an_error(): void
    {
        $listing = $this->listing();

        $workbook = $this->makeWorkbook([
            'Listings' => [['id'], [$listing->id]],
            'RoomTypes' => [
                ['listing_id', 'listing', 'code', 'name', 'total_units', 'rate_per_night'],
                ['', 'Lodge That Does Not Exist', 'STD', 'Standard', 2, '100'],
            ],
        ]);

        $plan = $this->importer()->plan($workbook);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('Lodge That Does Not Exist', $plan->invalidRoomRows()[0]->errors[0]);
        $this->assertSame(0, BookableUnit::count());
    }

    public function test_room_photos_come_from_a_folder_inside_the_listing_folder(): void
    {
        $listing = $this->listing();

        $zip = $this->makeZip([
            'Okonjima Bush Camp/cover.jpg' => 'listing-cover',
            'Okonjima Bush Camp/STD/01-bed.jpg' => 'bed-bytes',
            'Okonjima Bush Camp/STD/02-bath.jpg' => 'bath-bytes',
        ]);

        $workbook = $this->makeWorkbook([
            'Listings' => [['id', 'photo_folder'], [$listing->id, 'Okonjima Bush Camp']],
            'RoomTypes' => [
                ['listing_id', 'code', 'name', 'total_units', 'rate_per_night', 'photo_folder'],
                [$listing->id, 'STD', 'Standard Chalet', 4, '1450', 'Okonjima Bush Camp/STD'],
            ],
        ]);

        $plan = $this->importer()->plan($workbook, $zip);

        $this->assertFalse($plan->hasErrors(), implode(' / ', $plan->invalidRoomRows()[0]->errors ?? []));
        $this->assertSame(2, $plan->photoCount());
        $this->importer()->apply($plan, $zip);

        $room = BookableUnit::where('listing_id', $listing->id)->where('code', 'STD')->firstOrFail();
        $this->assertCount(2, $room->gallery ?? []);
        $this->assertStringStartsWith('room-types/okonjima-bush-camp/STD/', $room->gallery[0]);
        Storage::disk('r2')->assertExists($room->gallery[0]);

        // The listing's own folder must not swallow the room's photos.
        $listing->refresh();
        $this->assertStringContainsString('cover-', (string) $listing->image);
        $this->assertSame([], $listing->gallery);
    }

    public function test_a_room_folder_name_that_matches_several_listings_asks_for_the_path(): void
    {
        $listing = $this->listing();
        $other = $this->listing(['name' => 'Desert Whisper', 'slug' => 'desert-whisper']);

        $zip = $this->makeZip([
            'Okonjima Bush Camp/STD/bed.jpg' => 'a',
            'Desert Whisper/STD/bed.jpg' => 'b',
        ]);

        $workbook = $this->makeWorkbook([
            'Listings' => [['id'], [$listing->id]],
            'RoomTypes' => [
                ['listing_id', 'code', 'name', 'total_units', 'rate_per_night', 'photo_folder'],
                [$listing->id, 'STD', 'Standard', 2, '900', 'STD'],
            ],
        ]);

        $plan = $this->importer()->plan($workbook, $zip);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('matches several folders', $plan->invalidRoomRows()[0]->errors[0]);
        $this->assertSame(0, BookableUnit::count());
        $this->assertNotNull($other->id);
    }

    public function test_the_export_carries_bookable_units_and_reimports_as_a_no_op(): void
    {
        $listing = $this->listing();
        BookableUnit::create([
            'listing_id' => $listing->id,
            'code' => 'FAM',
            'name' => 'Family Chalet',
            'max_adults' => 2,
            'max_children' => 2,
            'total_units' => 4,
            'rate_per_night' => 2100.00,
            'currency' => 'NAD',
            'is_active' => true,
        ]);

        app(ListingExporter::class)->export(Listing::query(), $this->workbook);

        $rooms = app(SpreadsheetReader::class)->read($this->workbook, BookableUnitSheet::SHEET_NAME);
        $this->assertCount(1, $rooms['rows']);
        $this->assertContains('FAM', $rooms['rows'][0]['cells']);

        $plan = $this->importer()->plan($this->workbook);

        $this->assertFalse($plan->hasErrors());
        $this->assertSame([], $plan->applicableRows());
        $this->assertSame([], $plan->applicableRoomRows());
    }
}
