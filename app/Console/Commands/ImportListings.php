<?php

namespace App\Console\Commands;

use App\Services\ImportExport\ImportPlan;
use App\Services\ImportExport\ImportReportWriter;
use App\Services\ImportExport\ListingImporter;
use App\Services\ImportExport\RoomTypeSheet;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports a listings workbook. Always runs the dry run first and prints it —
 * writing only happens after --force or an interactive confirmation, and a row
 * with any error stops the whole file unless --skip-invalid says otherwise.
 */
class ImportListings extends Command
{
    protected $signature = 'namibway:import-listings
                            {file : Path to the .xlsx or .csv file}
                            {--photos= : Path to the photo ZIP (folders named in the photo_folder column)}
                            {--dry-run : Only show what would change}
                            {--skip-invalid : Import the valid rows instead of stopping on errors}
                            {--force : Skip the confirmation prompt}
                            {--report= : Write a detailed report to this .xlsx path}';

    protected $description = 'Import listings from an Excel workbook (updates by id, creates rows without one)';

    public function handle(ListingImporter $importer, ImportReportWriter $reportWriter): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $photos = $this->option('photos');
        $photos = is_string($photos) && $photos !== '' ? $photos : null;

        if ($photos !== null && ! is_file($photos)) {
            $this->error("ZIP not found: {$photos}");

            return self::FAILURE;
        }

        $plan = $importer->plan($file, $photos);

        $this->summarize($plan);

        if (is_string($report = $this->option('report')) && $report !== '') {
            $reportWriter->write($plan, $report);
            $this->line("Report: {$report}");
        }

        if ($plan->isBlocked()) {
            return self::FAILURE;
        }

        if ($plan->hasErrors() && ! $this->option('skip-invalid')) {
            $this->error('Import aborted — fix the errors and try again (or pass --skip-invalid).');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was saved.');

            return self::SUCCESS;
        }

        if ($plan->applicableRows() === []) {
            $this->info('No changes.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Save these changes?', true)) {
            return self::SUCCESS;
        }

        $written = $importer->apply($plan, $photos);

        $this->info("{$written} record(s) saved.");

        return self::SUCCESS;
    }

    private function summarize(ImportPlan $plan): void
    {
        foreach ($plan->fileErrors as $error) {
            $this->error($error);
        }

        foreach ($plan->ignoredHeaders as $header) {
            $this->warn("Ignored unknown column: \"{$header}\"");
        }

        if ($plan->rows === []) {
            return;
        }

        $this->newLine();
        $this->line(sprintf(
            '%d new · %d updated (%d field changes) · %d unchanged · %d with errors',
            $plan->newCount(),
            $plan->updateCount(),
            $plan->changeCount(),
            $plan->unchangedCount(),
            count($plan->invalidRows()),
        ));

        if ($plan->roomRows !== []) {
            $this->line(sprintf(
                'Room types: %d new · %d updated · %d with errors',
                $plan->roomNewCount(),
                $plan->roomUpdateCount(),
                count($plan->invalidRoomRows()),
            ));
        }

        if ($plan->photoCount() > 0) {
            $this->line($plan->photoCount().' listing(s) get their photos replaced from the ZIP.');
        }
        $this->newLine();

        foreach ($plan->invalidRows() as $row) {
            foreach ($row->errors as $error) {
                $this->error("Row {$row->line}: {$error}");
            }
        }

        foreach ($plan->invalidRoomRows() as $row) {
            foreach ($row->errors as $error) {
                $this->error("RoomTypes row {$row->line}: {$error}");
            }
        }

        foreach ($plan->rowsWithWarnings() as $row) {
            foreach ($row->warnings as $warning) {
                $this->warn("Row {$row->line}: {$warning}");
            }
        }

        $changes = [];

        foreach ($plan->applicableRows() as $row) {
            if ($row->isNew) {
                $changes[] = [$row->line, 'new', $row->name, '', ''];

                continue;
            }

            foreach ($row->changes as $change) {
                $changes[] = [
                    $row->line,
                    (string) $row->listingId,
                    $row->name,
                    $change->column,
                    Str::limit($change->old ?? '—', 40).'  →  '.Str::limit($change->new ?? '—', 40),
                ];
            }
        }

        foreach ($plan->applicableRoomRows() as $row) {
            $changes[] = [
                $row->line,
                (string) $row->listingId,
                RoomTypeSheet::SHEET_NAME.': '.$row->label(),
                $row->isNew ? 'new room type' : 'updated',
                Str::limit(implode(', ', array_map(static fn ($change) => $change->column, $row->changes)), 40),
            ];
        }

        if ($changes !== []) {
            $this->table(['Row', 'id', 'Listing', 'Field', 'Change'], array_slice($changes, 0, 100));

            if (count($changes) > 100) {
                $this->line('… '.(count($changes) - 100).' more. Pass --report=<file.xlsx> for the full list.');
            }
        }
    }
}
