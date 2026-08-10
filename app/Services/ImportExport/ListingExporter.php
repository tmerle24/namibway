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

        $writer->addRow(Row::fromValues(['Spalte', 'Bedeutung'], $bold));

        foreach (ListingSheet::columns() as $column) {
            $writer->addRow(Row::fromValues([$column->header, $column->help]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['So wird importiert', ''], $bold));

        foreach ([
            'Leere Zelle = Feld bleibt unverändert. Ein Feld absichtlich leeren: '.implode(' oder ', ListingSheet::CLEAR_MARKERS).' eintragen.',
            'Zeile mit id = dieses Listing wird aktualisiert. Zeile ohne id = neues Listing.',
            'Die id niemals überschreiben oder von Hand vergeben.',
            'Ja/Nein-Spalten: ja, nein (auch yes/no, x, 1, 0).',
            'Vor jedem Import läuft die Prüfung. Sie zeigt jede Änderung an, bevor etwas gespeichert wird.',
        ] as $line) {
            $writer->addRow(Row::fromValues(['', $line]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Erlaubte Werte', ''], $bold));
        $writer->addRow(Row::fromValues(['typ', 'accommodation, activity, restaurant, vehicle']));
        $writer->addRow(Row::fromValues(['fahrzeug_kategorie', 'self_drive, guided_tour']));
        $writer->addRow(Row::fromValues(['waehrung', implode(', ', ListingSheet::supportedCurrencies())]));

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Orte (Spalte "stadt")', 'Region'], $bold));

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
            'name' => 'Beispiel Lodge (Zeile vor dem Import löschen)',
            'typ' => 'accommodation',
            'stadt' => 'Outjo',
            'adresse' => 'C38, 10 km südlich Andersson Gate',
            'koordinaten' => '-19.183000, 15.917000',
            'beschreibung_kurz' => 'Kurz gesagt, wofür man herkommt.',
            'preis_ab' => 1250,
            'waehrung' => 'NAD',
            'email' => 'info@beispiel-lodge.na',
            'telefon' => '+264 61 123456',
            'veroeffentlichen' => 'nein',
            default => null,
        };
    }
}
