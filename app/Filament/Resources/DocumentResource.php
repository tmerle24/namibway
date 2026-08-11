<?php

namespace App\Filament\Resources;

use App\Enums\DocumentKind;
use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use App\Models\DocumentCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    protected static ?string $navigationGroup = 'Documents';

    protected static ?int $navigationSort = 1;

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
                            ->relationship('category', 'name')
                            ->required()
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(DocumentCategory::class, 'name'),
                                Forms\Components\Select::make('icon')
                                    ->options(DocumentCategoryResource::icons())
                                    ->default('heroicon-o-folder')
                                    ->native(false),
                                Forms\Components\Textarea::make('description')
                                    ->rows(2)
                                    ->maxLength(500),
                            ]),

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
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category')->withCount('notes'))
            ->recordUrl(fn (Document $record): string => static::getUrl('view', ['record' => $record]))
            ->groups([
                Tables\Grouping\Group::make('category.name')->label('Folder')->collapsible(),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Document $record): ?string => $record->description
                        ? str($record->description)->limit(90)->toString()
                        : null)
                    ->wrap(),
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Folder')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('kind')
                    ->label('Type')
                    ->badge(),
                Tables\Columns\TextColumn::make('size')
                    ->label('Size')
                    ->getStateUsing(fn (Document $record): string => $record->humanSize() ?? '—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('notes_count')
                    ->label('Comments')
                    ->badge()
                    ->color(fn (?int $state): string => ($state ?? 0) > 0 ? 'primary' : 'gray'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last changed')
                    ->dateTime('d M Y, H:i')
                    ->description(fn (Document $record): string => $record->editor_name ?? $record->author_name)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('document_category_id')
                    ->label('Folder')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('kind')
                    ->label('Type')
                    ->options(fn (): array => collect(DocumentKind::cases())
                        ->mapWithKeys(fn (DocumentKind $kind): array => [$kind->value => $kind->getLabel()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Document $record): string => route('documents.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Document $record): bool => $record->isFile()),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nothing filed yet')
            ->emptyStateDescription('Upload a file, or write a page — both end up in the same folder.');
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
