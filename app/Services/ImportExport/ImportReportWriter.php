<?php

namespace App\Services\ImportExport;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Turns an ImportPlan into a workbook the content team can work from: what went
 * wrong (with the row number to jump to), what would change, and what looked
 * suspicious but wasn't blocking. Written for both the dry run and the real
 * import, so an import always leaves a record of what it did.
 */
class ImportReportWriter
{
    public function write(ImportPlan $plan, string $path): void
    {
        $writer = new Writer;
        $options = $writer->getOptions();
        $options->setColumnWidth(10, 1);
        $options->setColumnWidth(30, 2);
        $options->setColumnWidth(90, 3);

        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Errors');

        $bold = (new Style)->setFontBold();
        $writer->addRow(Row::fromValues(['Row', 'Listing', 'Problem'], $bold));

        foreach ($plan->fileErrors as $error) {
            $writer->addRow(Row::fromValues(['', 'File', $error]));
        }

        foreach ($plan->invalidRows() as $row) {
            foreach ($row->errors as $error) {
                $writer->addRow(Row::fromValues([$row->line, $row->name, $error]));
            }
        }

        foreach ($plan->invalidRoomRows() as $row) {
            foreach ($row->errors as $error) {
                $writer->addRow(Row::fromValues([$row->line, BookableUnitSheet::SHEET_NAME.': '.$row->label(), $error]));
            }
        }

        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Changes');
        $sheet->setColumnWidth(10, 1);
        $sheet->setColumnWidth(10, 2);
        $sheet->setColumnWidth(30, 3);
        $sheet->setColumnWidth(20, 4);
        $sheet->setColumnWidth(45, 5);
        $sheet->setColumnWidth(45, 6);

        $writer->addRow(Row::fromValues(['Row', 'id', 'Listing', 'Field', 'before', 'after'], $bold));

        foreach ($plan->applicableRows() as $row) {
            if ($row->changes === []) {
                $writer->addRow(Row::fromValues([$row->line, '', $row->name, 'created', '', '']));

                continue;
            }

            foreach ($row->changes as $change) {
                $writer->addRow(Row::fromValues([
                    $row->line,
                    $row->listingId,
                    $row->name,
                    $change->column,
                    $change->old,
                    $change->new,
                ]));
            }
        }

        foreach ($plan->applicableRoomRows() as $row) {
            if ($row->changes === []) {
                $writer->addRow(Row::fromValues([$row->line, '', BookableUnitSheet::SHEET_NAME.': '.$row->label(), 'created', '', '']));

                continue;
            }

            foreach ($row->changes as $change) {
                $writer->addRow(Row::fromValues([
                    $row->line,
                    $row->listingId,
                    BookableUnitSheet::SHEET_NAME.': '.$row->label(),
                    $change->column,
                    $change->old,
                    $change->new,
                ]));
            }
        }

        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Notes');
        $sheet->setColumnWidth(10, 1);
        $sheet->setColumnWidth(30, 2);
        $sheet->setColumnWidth(90, 3);

        $writer->addRow(Row::fromValues(['Row', 'Listing', 'Note'], $bold));

        foreach ($plan->ignoredHeaders as $header) {
            $writer->addRow(Row::fromValues(['', 'File', "Column \"{$header}\" is unknown and was ignored."]));
        }

        foreach ($plan->rowsWithWarnings() as $row) {
            foreach ($row->warnings as $warning) {
                $writer->addRow(Row::fromValues([$row->line, $row->name, $warning]));
            }
        }

        $writer->close();
    }
}
