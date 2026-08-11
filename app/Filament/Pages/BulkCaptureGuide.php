<?php

namespace App\Filament\Pages;

use App\Services\ImportExport\BookableUnitSheet;
use App\Services\ImportExport\ListingSheet;
use App\Services\ImportExport\PhotoArchive;
use App\Services\ImportExport\SheetColumn;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The handbook for the Excel capture workflow, for the people who actually do
 * the capturing. It reads the column definitions out of ListingSheet and
 * BookableUnitSheet rather than repeating them, so the guide cannot drift from the
 * workbook the export hands out.
 */
class BulkCaptureGuide extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Bulk Capture Guide';

    protected static ?string $navigationGroup = 'Documentation';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Capturing listings in Excel';

    protected static string $view = 'filament.pages.bulk-capture-guide';

    public function getSubheading(): string|Htmlable|null
    {
        return 'Export what exists, edit it in Excel, import it back — with photos and room types.';
    }

    /**
     * @return list<array{header: string, help: string, required: bool}>
     */
    public function listingColumns(): array
    {
        return $this->describe(ListingSheet::columns());
    }

    /**
     * @return list<array{header: string, help: string, required: bool}>
     */
    public function roomColumns(): array
    {
        return $this->describe(BookableUnitSheet::columns());
    }

    /**
     * @return array<string, string>
     */
    public function photoLimits(): array
    {
        return [
            'formats' => implode(', ', PhotoArchive::EXTENSIONS),
            'per_listing' => (string) PhotoArchive::MAX_FILES_PER_FOLDER,
            'per_file' => round(PhotoArchive::MAX_FILE_BYTES / 1024 / 1024).' MB',
        ];
    }

    /**
     * @return list<string>
     */
    public function clearMarkers(): array
    {
        return ListingSheet::CLEAR_MARKERS;
    }

    /**
     * @param  list<SheetColumn>  $columns
     * @return list<array{header: string, help: string, required: bool}>
     */
    private function describe(array $columns): array
    {
        return array_map(static fn ($column) => [
            'header' => $column->header,
            'help' => $column->help,
            'required' => $column->requiredForNew,
        ], $columns);
    }
}
