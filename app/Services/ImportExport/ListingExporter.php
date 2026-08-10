<?php

namespace App\Services\ImportExport;

use App\Models\City;
use App\Models\Listing;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes the listings workbook: a "Listings" sheet with one row per listing, a
 * "RoomTypes" sheet with their bookable units, and an "Instructions" sheet that
 * explains every column and lists the valid cities and types. The `id` column it
 * writes is what makes a later re-import an update instead of a duplicate, so an
 * export is the intended starting point for bulk editing — see ListingImporter.
 */
class ListingExporter
{
    /**
     * @param  Builder<Listing>  $query
     * @return int number of listings written
     */
    public function export(Builder $query, string $path): int
    {
        $columns = ListingSheet::columns();
        $written = 0;

        $writer = $this->open($path, $columns);

        $ids = [];

        $query->with('city')->lazyById(500)->each(function (Listing $listing) use ($writer, $columns, &$written, &$ids): void {
            $writer->addRow(Row::fromValues(array_map(
                static fn (SheetColumn $column) => ListingSheet::cellValue($column, $listing),
                $columns,
            )));

            $ids[] = $listing->id;
            $written++;
        });

        $this->addRoomTypeSheet($writer, $ids);
        $this->addHelpSheet($writer);
        $writer->close();

        return $written;
    }

    /**
     * An empty workbook for capturing listings that don't exist yet: headers, one
     * commented example row, and the same help sheet.
     */
    public function template(string $path): void
    {
        $columns = ListingSheet::columns();

        $writer = $this->open($path, $columns);

        $writer->addRow(Row::fromValues(array_map(
            static fn (SheetColumn $column) => self::exampleValue($column),
            $columns,
        )));

        $this->addRoomTypeSheet($writer, []);
        $this->addHelpSheet($writer);
        $writer->close();
    }

    /**
     * The room types of the listings just written — an accommodation's bookable
     * units, on their own sheet because there are several per listing.
     *
     * @param  list<int>  $listingIds  empty for the template: headers only
     */
    private function addRoomTypeSheet(Writer $writer, array $listingIds): void
    {
        $columns = RoomTypeSheet::columns();

        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(RoomTypeSheet::SHEET_NAME);
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));

        foreach ($columns as $index => $column) {
            $sheet->setColumnWidth($column->width, $index + 1);
        }

        $writer->addRow(Row::fromValues(
            array_map(static fn (SheetColumn $column) => $column->header, $columns),
            (new Style)->setFontBold(),
        ));

        if ($listingIds === []) {
            return;
        }

        RoomType::query()
            ->with('listing')
            ->whereIn('listing_id', $listingIds)
            ->orderBy('listing_id')
            ->orderBy('code')
            ->lazy()
            ->each(static function (RoomType $roomType) use ($writer, $columns): void {
                $writer->addRow(Row::fromValues(array_map(
                    static fn (SheetColumn $column) => RoomTypeSheet::cellValue($column, $roomType),
                    $columns,
                )));
            });
    }

    /**
     * @param  list<SheetColumn>  $columns
     */
    private function open(string $path, array $columns): Writer
    {
        $writer = new Writer;
        $options = $writer->getOptions();

        foreach ($columns as $index => $column) {
            $options->setColumnWidth($column->width, $index + 1);
        }

        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(ListingSheet::SHEET_NAME);
        $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));

        $writer->addRow(Row::fromValues(
            array_map(static fn (SheetColumn $column) => $column->header, $columns),
            (new Style)->setFontBold(),
        ));

        return $writer;
    }

    private function addHelpSheet(Writer $writer): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(ListingSheet::HELP_SHEET_NAME);
        $sheet->setColumnWidth(28, 1);
        $sheet->setColumnWidth(110, 2);

        $bold = (new Style)->setFontBold();

        $writer->addRow(Row::fromValues(['Column', 'What it means'], $bold));

        foreach (ListingSheet::columns() as $column) {
            $writer->addRow(Row::fromValues([$column->header, $column->help]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['How the import reads this', ''], $bold));

        foreach ([
            'An empty cell leaves the field as it is. To empty a field on purpose, write '.implode(' or ', ListingSheet::CLEAR_MARKERS).'.',
            'A row with an id updates that listing. A row without an id creates a new one.',
            'Never overwrite an id or make one up.',
            'Yes/no columns: yes, no (y/n, x, 1, 0 work too).',
            'Every import is checked first: it lists every change before anything is saved.',
        ] as $line) {
            $writer->addRow(Row::fromValues(['', $line]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['The "'.RoomTypeSheet::SHEET_NAME.'" sheet', ''], $bold));

        foreach (RoomTypeSheet::columns() as $column) {
            $writer->addRow(Row::fromValues([$column->header, $column->help]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Photos', ''], $bold));

        foreach ([
            'Put one folder per listing in a ZIP file and upload it together with this workbook.',
            'Write the folder name in the "photo_folder" column — nothing else is needed.',
            'A file whose name starts with "cover" becomes the main image; the rest become the gallery, in name order.',
            implode('/', PhotoArchive::EXTENSIONS).' only, at most '.PhotoArchive::MAX_FILES_PER_FOLDER.' images per listing and '.round(PhotoArchive::MAX_FILE_BYTES / 1024 / 1024).' MB per image.',
            'Filling in photo_folder REPLACES the photos that listing already has. Only upload photos we are allowed to publish.',
        ] as $line) {
            $writer->addRow(Row::fromValues(['', $line]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Allowed values', ''], $bold));
        $writer->addRow(Row::fromValues(['type', 'accommodation, activity, restaurant, vehicle']));
        $writer->addRow(Row::fromValues(['vehicle_category', 'self_drive, guided_tour']));
        $writer->addRow(Row::fromValues(['currency', implode(', ', ListingSheet::supportedCurrencies())]));

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Places (the "city" column)', 'Region'], $bold));

        City::query()
            ->with('region')
            ->orderBy('name')
            ->lazy()
            ->each(static function (City $city) use ($writer): void {
                $writer->addRow(Row::fromValues([$city->name, $city->region?->name]));
            });
    }

    private static function exampleValue(SheetColumn $column): string|int|null
    {
        return match ($column->header) {
            'name' => 'Example Lodge (delete this row before importing)',
            'type' => 'accommodation',
            'city' => 'Outjo',
            'address' => 'C38, 10 km south of Andersson Gate',
            'coordinates' => '-19.183000, 15.917000',
            'short_description' => 'In one line: what people come here for.',
            'price_from' => 1250,
            'currency' => 'NAD',
            'email' => 'info@example-lodge.na',
            'phone' => '+264 61 123456',
            'published' => 'no',
            default => null,
        };
    }
}
