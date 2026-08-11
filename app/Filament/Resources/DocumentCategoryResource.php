<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentCategoryResource\Pages;
use App\Models\DocumentCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The folders of the team's document store.
 *
 * Deliberately thin: a folder is a name, a line of explanation and a place in
 * the list. Everything worth looking at is one level down, in DocumentResource.
 */
class DocumentCategoryResource extends Resource
{
    protected static ?string $model = DocumentCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'Folders';

    protected static ?string $navigationGroup = 'Documents';

    protected static ?string $modelLabel = 'folder';

    protected static ?string $pluralModelLabel = 'folders';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * A short list of heroicons that actually mean something for this kind of
     * filing. A free-text icon field is a way to end up with a broken icon on
     * the one screen everybody uses.
     *
     * @return array<string, string>
     */
    public static function icons(): array
    {
        return [
            'heroicon-o-folder' => 'Folder',
            'heroicon-o-megaphone' => 'Marketing',
            'heroicon-o-briefcase' => 'Business',
            'heroicon-o-photo' => 'Brand & logos',
            'heroicon-o-scale' => 'Legal',
            'heroicon-o-banknotes' => 'Finance',
            'heroicon-o-user-group' => 'Team',
            'heroicon-o-map' => 'Product',
            'heroicon-o-wrench-screwdriver' => 'Operations',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('e.g. Marketing material'),
                Forms\Components\Select::make('icon')
                    ->label('Icon')
                    ->options(static::icons())
                    ->default('heroicon-o-folder')
                    ->native(false),
                Forms\Components\Textarea::make('description')
                    ->label('What belongs in here')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Optional. One line is enough — it is what stops the next person filing into the wrong folder.')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('position')
                    ->label('Position')
                    ->numeric()
                    ->default(0)
                    ->helperText('Low numbers first. Drag the rows in the list to change this.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('position')
            ->defaultSort('position')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('documents'))
            ->columns([
                Tables\Columns\IconColumn::make('icon')
                    ->label('')
                    ->icon(fn (DocumentCategory $record): string => $record->icon ?: 'heroicon-o-folder'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->url(fn (DocumentCategory $record): string => DocumentResource::getUrl('index', [
                        'tableFilters' => ['document_category_id' => ['value' => $record->id]],
                    ])),
                Tables\Columns\TextColumn::make('documents_count')
                    ->label('Documents')
                    ->badge(),
                Tables\Columns\TextColumn::make('description')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    // The database refuses this anyway (restrictOnDelete), but a
                    // foreign-key error is not an explanation. Emptying the
                    // folder first is the deliberate step.
                    ->disabled(fn (DocumentCategory $record): bool => ($record->documents_count ?? 0) > 0)
                    ->tooltip(fn (DocumentCategory $record): ?string => ($record->documents_count ?? 0) > 0
                        ? 'Move or delete the documents in this folder first.'
                        : null),
            ])
            ->emptyStateHeading('No folders yet')
            ->emptyStateDescription('Folders are how the team finds things later — "Marketing", "Business", "Brand assets".');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentCategories::route('/'),
            'create' => Pages\CreateDocumentCategory::route('/create'),
            'edit' => Pages\EditDocumentCategory::route('/{record}/edit'),
        ];
    }
}
