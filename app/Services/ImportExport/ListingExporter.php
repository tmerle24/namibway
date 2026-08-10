<?php

namespace App\Services\ImportExport;

use App\Models\City;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes the listings workbook: one row per listing, plus an "Anleitung" sheet
 * that explains every column and lists the valid cities and types. The `id`
 * column it writes is what makes a later re-import an update instead of a
 * duplicate, so an export is the intended starting point for bulk editing —
 * see ListingImporter.
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

        $query->with('city')->lazyById(500)->each(function (Listing $listing) use ($writer, $columns, &$written): void {
            $writer->addRow(Row::fromValues(array_map(
                static fn (SheetColumn $column) => ListingSheet::cellValue($column, $listing),
                $columns,
            )));

            $written++;
        });

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

        $this->addHelpSheet($writer);
        $writer->close();
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
