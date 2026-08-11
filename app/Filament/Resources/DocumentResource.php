<?php

namespace App\Filament\Resources;

use App\Enums\DocumentKind;
use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use App\Models\DocumentCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The team's own filing cabinet: marketing material, business documents, brand
 * assets — and, since the same people kept asking where to write things down,
 * pages written directly in the panel (see App\Enums\DocumentKind).
 *
 * Scope on purpose: this is project management for the people running NamibWay,
 * not a partner-facing document store and not a second place to keep listing
 * photos. Nothing here is published anywhere; the files sit on the private disk
 * and leave the server only through DocumentDownloadController.
 */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationLabel = 'Documents';

    // Under Content rather than in a group of its own: one entry, and the panel
    // does not grow a section for it. Folders have no menu entry at all — they
    // are made and moved inside the explorer, where you can see where they land.
    protected static ?string $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'title';

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'description'];
    }

    /**
     * The form state for `kind` is a string while the radio is being clicked
     * and a DocumentKind once it has been hydrated from a saved row, so both
     * are accepted rather than having half the fields disappear on an edit.
     */
    private static function kindIs(Get $get, DocumentKind $kind): bool
    {
        $state = $get('kind');

        return $state instanceof DocumentKind
            ? $state === $kind
            : $state === $kind->value;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Radio::make('kind')
                            ->label('What is this?')
                            ->options(fn (): array => collect(DocumentKind::cases())
                                ->mapWithKeys(fn (DocumentKind $kind): array => [$kind->value => $kind->getLabel()])
                                ->all())
                            ->descriptions(fn (): array => collect(DocumentKind::cases())
                                ->mapWithKeys(fn (DocumentKind $kind): array => [$kind->value => $kind->description()])
                                ->all())
                            ->default(DocumentKind::File->value)
                            ->required()
                            ->live()
                            // Changing an upload into a page (or back) would
                            // leave the row half-empty and the file orphaned.
                            // Two different things: make the other one.
                            ->disabledOn('edit')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Partner agreement 2026')
                            ->columnSpanFull(),

                        Forms\Components\Select::make('document_category_id')
                            ->label('Folder')
                            ->options(fn (): array => DocumentCategory::options())
                            // Empty is the top level, which is a real place to
                            // file something — see the tree migration.
                            ->placeholder('Top level')
                            ->native(false)
                            ->searchable()
                            ->helperText('Where this is filed. Leave it empty to keep it at the top level.'),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('What this is and when to use it. Shown in the list and above the document.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('File')
                    ->visible(fn (Get $get): bool => self::kindIs($get, DocumentKind::File))
                    ->schema([
                        Forms\Components\FileUpload::make('path')
                            ->label('Document')
                            ->required(fn (Get $get): bool => self::kindIs($get, DocumentKind::File))
                            // Private disk, not the public media bucket — see the
                            // documents migration for why this one is different.
                            ->disk(Document::DISK)
                            ->directory(Document::DIRECTORY)
                            ->visibility('private')
                            // The stored name is random; this keeps the name the
                            // file was uploaded under, which is what a download
                            // is saved as later.
                            ->storeFileNamesIn('original_name')
                            ->maxSize(51_200)
                            ->helperText('Up to 50 MB. PDFs, images, spreadsheets, presentations, archives.')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Page')
                    ->visible(fn (Get $get): bool => self::kindIs($get, DocumentKind::Page))
                    ->schema([
                        Forms\Components\RichEditor::make('body')
                            ->label('Content')
                            ->required(fn (Get $get): bool => self::kindIs($get, DocumentKind::Page))
                            // No attachment button on purpose: attachments would
                            // land on the public disk, which is exactly what
                            // this feature avoids. A picture that belongs to a
                            // page is filed as a document in the same folder.
                            ->toolbarButtons([
                                'bold', 'italic', 'strike', 'link',
                                'h2', 'h3', 'blockquote', 'codeBlock',
                                'bulletList', 'orderedList',
                                'undo', 'redo',
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Alphabetical, the way a folder listing is expected to open. Date,
            // type and size are each one click on a column header away, and the
            // filters narrow by type or by when something last changed.
            ->defaultSort('title')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('notes'))
            ->recordUrl(fn (Document $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                // A logo or a photo is recognised by looking at it, not by
                // reading its name. Non-images have no state, so the cell is
                // simply empty and the row keeps its icon in the name column.
                //
                // The preview is the stored original, streamed through the
                // download route — the /thumbs pipeline resizes objects in the
                // public media bucket, and these deliberately are not there.
                // Acceptable on an internal screen showing ten rows; it would
                // not be on a traveller-facing one.
                Tables\Columns\ImageColumn::make('preview')
                    ->label('')
                    ->getStateUsing(fn (Document $record): ?string => $record->isImage()
                        ? route('documents.download', $record)
                        : null)
                    ->checkFileExistence(false)
                    ->size(40)
                    ->extraImgAttributes(['loading' => 'lazy', 'class' => 'object-cover rounded'])
                    ->square(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Name')
                    // The search box looks where somebody would look for it: the
                    // name they gave it, the name the file arrived under, and
                    // what they wrote about it. A page's body is deliberately
                    // not in here — see the note on the search box below.
                    ->searchable(['title', 'description', 'original_name'])
                    ->sortable()
                    ->weight('medium')
                    ->icon(fn (Document $record): string => $record->kind->getIcon())
                    ->description(fn (Document $record): ?string => $record->description
                        ? str($record->description)->limit(90)->toString()
                        : null)
                    ->wrap(),
                Tables\Columns\TextColumn::make('original_name')
                    ->label('File name')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('size')
                    ->label('Size')
                    ->getStateUsing(fn (Document $record): string => $record->humanSize() ?? '—')
                    // Sorts on the stored byte count rather than on the "1.4 MB"
                    // the cell shows, which would put 9 KB after 10 MB.
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('notes_count')
                    ->label('Comments')
                    ->badge()
                    ->color(fn (?int $state): string => ($state ?? 0) > 0 ? 'primary' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Filed')
                    ->dateTime('d M Y, H:i')
                    ->description(fn (Document $record): string => $record->author_name)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last changed')
                    ->dateTime('d M Y, H:i')
                    ->description(fn (Document $record): string => $record->editor_name ?? $record->author_name)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->label('Type')
                    ->options(fn (): array => collect(DocumentKind::cases())
                        ->mapWithKeys(fn (DocumentKind $kind): array => [$kind->value => $kind->getLabel()])
                        ->all()),
                Tables\Filters\Filter::make('changed')
                    ->label('Last changed')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('updated_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('updated_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'Changed from '.$data['from'];
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Changed until '.$data['until'];
                        }

                        return $indicators;
                    }),
            ])
            ->searchPlaceholder('Search name, file name, description')
            // Collapsed into one menu per row: four labelled buttons left the
            // name column two words wide, and a name you cannot read is worse
            // than a click you have to make.
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('download')
                        ->label('Open file')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (Document $record): string => route('documents.download', $record))
                        ->openUrlInNewTab()
                        ->visible(fn (Document $record): bool => $record->isFile()),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('move')
                        ->label('Move to…')
                        ->icon('heroicon-o-arrow-right-circle')
                        ->modalWidth('lg')
                        ->modalSubmitActionLabel('Move')
                        ->fillForm(fn (Document $record): array => [
                            'document_category_id' => $record->document_category_id,
                        ])
                        ->form([
                            Forms\Components\Select::make('document_category_id')
                                ->label('Folder')
                                ->options(fn (): array => DocumentCategory::options())
                                ->placeholder('Top level')
                                ->native(false)
                                ->searchable(),
                        ])
                        ->action(function (Document $record, array $data): void {
                            $record->update(['document_category_id' => $data['document_category_id'] ?? null]);

                            Notification::make()->success()->title('Moved')->send();
                        }),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('move')
                        ->label('Move to…')
                        ->icon('heroicon-o-arrow-right-circle')
                        ->modalWidth('lg')
                        ->modalSubmitActionLabel('Move')
                        ->deselectRecordsAfterCompletion()
                        ->form([
                            Forms\Components\Select::make('document_category_id')
                                ->label('Folder')
                                ->options(fn (): array => DocumentCategory::options())
                                ->placeholder('Top level')
                                ->native(false)
                                ->searchable(),
                        ])
                        ->action(fn (Collection $records, array $data) => self::moveAll(
                            $records,
                            $data['document_category_id'] ?? null,
                        )),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nothing filed here')
            ->emptyStateDescription('Add a document, or open one of the folders above.');
    }

    /**
     * Named method rather than an inline closure so $records carries an
     * explicit generic type — PHPStan cannot infer Collection<int, Document>
     * from an untyped closure parameter (same reason as
     * ListingResource::assignCity).
     *
     * @param  Collection<int, Document>  $records
     */
    private static function moveAll(Collection $records, mixed $folder): void
    {
        $folder = is_numeric($folder) ? (int) $folder : null;

        $records->each(fn (Document $record) => $record->update([
            'document_category_id' => $folder,
        ]));

        Notification::make()
            ->success()
            ->title(trans_choice('{1}Document moved|[2,*]:count documents moved', $records->count(), [
                'count' => $records->count(),
            ]))
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'view' => Pages\ViewDocument::route('/{record}'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
