<?php

namespace App\Filament\Pages;

use App\Filament\Support\WorkbookDownload;
use App\Services\ImportExport\ImportPlan;
use App\Services\ImportExport\ImportReportWriter;
use App\Services\ImportExport\ListingExporter;
use App\Services\ImportExport\ListingImporter;
use App\Services\ImportExport\PlannedRoomRow;
use App\Services\ImportExport\PlannedRow;
use App\Services\ImportExport\RoomTypeSheet;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk listing capture without the admin forms: upload the workbook, see exactly
 * what it would change, then import. The check step is not optional decoration —
 * it is the only thing standing between a typo in a spreadsheet and a few hundred
 * overwritten listings, so the import button always re-plans the same file.
 */
class ListingImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Import listings';

    /**
     * Reached from the "Import listings" button on the listings table, not from the
     * sidebar — it belongs next to the export it is the counterpart of.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static ?string $title = 'Import listings from Excel';

    protected static string $view = 'filament.pages.listing-import';

    /** How many rows the preview renders before it just counts the rest. */
    private const PREVIEW_LIMIT = 150;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('file')
                    ->label('Workbook')
                    ->helperText('.xlsx or .csv — start from an export or the template so the id column is filled in.')
                    ->disk('local')
                    ->directory('listing-imports')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                        'application/vnd.oasis.opendocument.spreadsheet',
                        'text/csv',
                        'text/plain',
                    ])
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->preview = null),
                Forms\Components\FileUpload::make('photos')
                    ->label('Photo ZIP (optional)')
                    ->helperText('One folder per listing, named exactly as in the workbook\'s photo_folder column. A file called cover.jpg becomes the main image.')
                    ->disk('local')
                    ->directory('listing-imports')
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'])
                    ->live()
                    ->afterStateUpdated(fn () => $this->preview = null),
                Forms\Components\Toggle::make('skip_invalid')
                    ->label('Import valid rows even if some rows have errors')
                    ->helperText('Off: a single bad row stops the whole file — the safer default.')
                    ->default(false),
            ])
            ->statePath('data');
    }

    public function check(): void
    {
        $path = $this->uploadedFilePath();

        if ($path === null) {
            return;
        }

        $this->preview = $this->summarize(app(ListingImporter::class)->plan($path, $this->uploadedZipPath()));

        Notification::make()
            ->title('File checked — nothing has been saved yet')
            ->info()
            ->send();
    }

    public function import(): void
    {
        $path = $this->uploadedFilePath();

        if ($path === null) {
            return;
        }

        $importer = app(ListingImporter::class);
        $zip = $this->uploadedZipPath();
        $plan = $importer->plan($path, $zip);
        $this->preview = $this->summarize($plan);

        if ($plan->isBlocked()) {
            Notification::make()->title('The file could not be read')->danger()->send();

            return;
        }

        $skipInvalid = (bool) ($this->data['skip_invalid'] ?? false);

        if ($plan->hasErrors() && ! $skipInvalid) {
            Notification::make()
                ->title('Nothing imported')
                ->body('Fix the rows listed below, or switch on "Import valid rows".')
                ->danger()
                ->send();

            return;
        }

        $written = $importer->apply($plan, $zip);

        Notification::make()
            ->title($written === 1 ? '1 record saved' : "{$written} records saved")
            ->body($plan->photoCount() > 0 ? $plan->photoCount().' listing(s) got their photos from the ZIP.' : null)
            ->success()
            ->send();

        $this->preview = $this->summarize($importer->plan($path, $zip));
    }

    public function downloadReport(): void
    {
        $path = $this->uploadedFilePath();

        if ($path === null) {
            return;
        }

        $name = 'listings-import-report-'.now()->format('Y-m-d-Hi').'.xlsx';
        $report = WorkbookDownload::path($name);
        app(ImportReportWriter::class)->write(app(ListingImporter::class)->plan($path, $this->uploadedZipPath()), $report);

        $this->redirect(WorkbookDownload::link($report, $name));
    }

    public function downloadTemplate(): void
    {
        $path = WorkbookDownload::path('listings-template.xlsx');
        app(ListingExporter::class)->template($path);

        $this->redirect(WorkbookDownload::link($path, 'listings-template.xlsx'));
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('guide')
                ->label('How this works')
                ->icon('heroicon-o-book-open')
                ->color('gray')
                ->url(BulkCaptureGuide::getUrl()),
            Action::make('downloadTemplate')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->downloadTemplate()),
        ];
    }

    public function checkAction(): Action
    {
        return Action::make('check')
            ->label('Check file')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->action(fn () => $this->check());
    }

    public function importAction(): Action
    {
        return Action::make('import')
            ->label('Import')
            ->icon('heroicon-o-arrow-up-tray')
            ->requiresConfirmation()
            ->modalHeading('Import listings')
            ->modalDescription('Rows with an id are updated, rows without one are created. Empty cells are left untouched.')
            ->modalSubmitActionLabel('Import')
            ->action(fn () => $this->import());
    }

    public function reportAction(): Action
    {
        return Action::make('report')
            ->label('Download report')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (): bool => $this->preview !== null)
            ->action(fn () => $this->downloadReport());
    }

    /** The photo ZIP is optional — no upload simply means "no photos in this import". */
    private function uploadedZipPath(): ?string
    {
        $state = $this->getForm('form')?->getState()['photos'] ?? null;

        if (is_array($state)) {
            $state = $state === [] ? null : reset($state);
        }

        if (! is_string($state) || $state === '') {
            return null;
        }

        $disk = Storage::disk('local');

        return $disk->exists($state) ? $disk->path($state) : null;
    }

    private function uploadedFilePath(): ?string
    {
        $state = $this->getForm('form')?->getState()['file'] ?? null;

        // FileUpload hands back either the stored path or a uuid-keyed array of them.
        if (is_array($state)) {
            $state = $state === [] ? null : reset($state);
        }

        if (! is_string($state) || $state === '') {
            Notification::make()->title('Please upload a file first')->warning()->send();

            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($state)) {
            Notification::make()->title('The uploaded file is gone — please upload it again')->danger()->send();

            return null;
        }

        return $disk->path($state);
    }

    /**
     * Flattens the plan into plain arrays — a Livewire property has to survive
     * being serialized between requests, which model-carrying objects don't.
     *
     * @return array<string, mixed>
     */
    private function summarize(ImportPlan $plan): array
    {
        $rows = [];

        foreach (array_slice($plan->applicableRows(), 0, self::PREVIEW_LIMIT) as $row) {
            $rows[] = [
                'line' => $row->line,
                'id' => $row->listingId,
                'name' => $row->name,
                'is_new' => $row->isNew,
                'changes' => array_map(static fn ($change) => [
                    'column' => $change->column,
                    'old' => $change->old,
                    'new' => $change->new,
                ], $row->changes),
            ];
        }

        $roomRows = [];

        foreach (array_slice($plan->applicableRoomRows(), 0, self::PREVIEW_LIMIT) as $room) {
            $roomRows[] = [
                'line' => $room->line,
                'label' => $room->label(),
                'is_new' => $room->isNew,
                'fields' => implode(', ', array_map(static fn ($change) => $change->column, $room->changes)),
            ];
        }

        return [
            'blocked' => $plan->isBlocked(),
            'file_errors' => $plan->fileErrors,
            'ignored_headers' => $plan->ignoredHeaders,
            'new' => $plan->newCount(),
            'updated' => $plan->updateCount(),
            'changes' => $plan->changeCount(),
            'unchanged' => $plan->unchangedCount(),
            'invalid' => count($plan->invalidRows()) + count($plan->invalidRoomRows()),
            'errors' => array_merge(
                $this->flattenMessages($plan->invalidRows(), 'errors'),
                $this->roomMessages($plan->invalidRoomRows()),
            ),
            'warnings' => $this->flattenMessages($plan->rowsWithWarnings(), 'warnings'),
            'rows' => $rows,
            'more_rows' => max(0, count($plan->applicableRows()) - count($rows)),
            'room_rows' => $roomRows,
            'room_new' => $plan->roomNewCount(),
            'room_updated' => $plan->roomUpdateCount(),
            'photos' => $plan->photoCount(),
        ];
    }

    /**
     * @param  list<PlannedRoomRow>  $rows
     * @return list<array{line: int, name: string, message: string}>
     */
    private function roomMessages(array $rows): array
    {
        $messages = [];

        foreach ($rows as $row) {
            foreach ($row->errors as $message) {
                $messages[] = [
                    'line' => $row->line,
                    'name' => RoomTypeSheet::SHEET_NAME.': '.$row->label(),
                    'message' => $message,
                ];
            }
        }

        return $messages;
    }

    /**
     * @param  list<PlannedRow>  $rows
     * @return list<array{line: int, name: string, message: string}>
     */
    private function flattenMessages(array $rows, string $kind): array
    {
        $messages = [];

        foreach ($rows as $row) {
            foreach ($kind === 'errors' ? $row->errors : $row->warnings as $message) {
                $messages[] = ['line' => $row->line, 'name' => $row->name, 'message' => $message];
            }
        }

        return $messages;
    }
}
