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
     * @param  list<PlannedRoomRow>  $roomRows  Rows of the BookableUnits sheet, if the workbook has one.
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly array $fileErrors = [],
        public readonly array $ignoredHeaders = [],
        public readonly array $roomRows = [],
    ) {}

    /** @return list<PlannedRoomRow> */
    public function invalidRoomRows(): array
    {
        return array_values(array_filter($this->roomRows, static fn (PlannedRoomRow $row) => ! $row->isValid()));
    }

    /** @return list<PlannedRoomRow> */
    public function applicableRoomRows(): array
    {
        return array_values(array_filter($this->roomRows, static fn (PlannedRoomRow $row) => $row->isApplicable()));
    }

    public function roomNewCount(): int
    {
        return count(array_filter($this->applicableRoomRows(), static fn (PlannedRoomRow $row) => $row->isNew));
    }

    public function roomUpdateCount(): int
    {
        return count(array_filter($this->applicableRoomRows(), static fn (PlannedRoomRow $row) => ! $row->isNew));
    }

    /** Whether apply() has to open the photo ZIP at all. */
    public function needsPhotos(): bool
    {
        foreach ($this->applicableRows() as $row) {
            if ($row->photoFolder !== null) {
                return true;
            }
        }

        foreach ($this->applicableRoomRows() as $row) {
            if ($row->photoFolder !== null) {
                return true;
            }
        }

        return false;
    }

    /** Listings and room types whose photos this import would replace. */
    public function photoCount(): int
    {
        return count(array_filter($this->applicableRows(), static fn (PlannedRow $row) => $row->photoFolder !== null))
            + count(array_filter($this->applicableRoomRows(), static fn (PlannedRoomRow $row) => $row->photoFolder !== null));
    }

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
        return $this->fileErrors !== [] || $this->invalidRows() !== [] || $this->invalidRoomRows() !== [];
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
