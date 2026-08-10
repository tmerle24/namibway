<?php

namespace App\Services\ImportExport;

/**
 * The full dry run: every row's verdict plus file-level problems. Nothing here
 * has touched the database yet — ListingImporter::apply() takes this exact
 * object, which is what makes "prüfen, dann importieren" trustworthy.
 */
final class ImportPlan
{
    /**
     * @param  list<PlannedRow>  $rows
     * @param  list<string>  $fileErrors  Problems that stop the whole import (missing headers, unreadable file).
     * @param  list<string>  $ignoredHeaders  Columns in the file that the sheet doesn't know — skipped, not fatal.
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly array $fileErrors = [],
        public readonly array $ignoredHeaders = [],
    ) {}

    /** @return list<PlannedRow> */
    public function invalidRows(): array
    {
        return array_values(array_filter($this->rows, static fn (PlannedRow $row) => ! $row->isValid()));
    }

    /** @return list<PlannedRow> */
    public function applicableRows(): array
    {
        return array_values(array_filter($this->rows, static fn (PlannedRow $row) => $row->isApplicable()));
    }

    /** @return list<PlannedRow> */
    public function rowsWithWarnings(): array
    {
        return array_values(array_filter($this->rows, static fn (PlannedRow $row) => $row->warnings !== []));
    }

    public function hasErrors(): bool
    {
        return $this->fileErrors !== [] || $this->invalidRows() !== [];
    }

    public function isBlocked(): bool
    {
        return $this->fileErrors !== [];
    }

    public function newCount(): int
    {
        return count(array_filter($this->applicableRows(), static fn (PlannedRow $row) => $row->isNew));
    }

    public function updateCount(): int
    {
        return count(array_filter($this->applicableRows(), static fn (PlannedRow $row) => ! $row->isNew));
    }

    public function unchangedCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (PlannedRow $row) => $row->isValid() && ! $row->isApplicable(),
        ));
    }

    public function changeCount(): int
    {
        return array_sum(array_map(static fn (PlannedRow $row) => count($row->changes), $this->applicableRows()));
    }
}
