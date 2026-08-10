<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReleaseNoteResource\Pages;
use App\Models\ReleaseNote;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ReleaseNoteResource extends Resource
{
    protected static ?string $model = ReleaseNote::class;

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationLabel = 'Release Notes';

    protected static ?string $navigationGroup = 'Documentation';

    /**
     * Out of the menu: the Documentation group already has a "Release Notes"
     * entry (the deploy log), and two items with the same name is one too many.
     * The resource itself stays reachable at /admin/release-notes for editing.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('version')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. 1.4.0')
                    ->unique(ignoreRecord: true),
                Forms\Components\DatePicker::make('released_at')
                    ->label('Released on')
                    ->required()
                    ->default(now()),
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('summary')
                    ->label('Short summary')
                    ->helperText('Shown in the release notes list.')
                    ->rows(2)
                    ->columnSpanFull(),
                Forms\Components\RichEditor::make('body')
                    ->label('What changed')
                    ->toolbarButtons([
                        'bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo',
                    ])
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make()
                    ->schema([
                        Infolists\Components\TextEntry::make('version')
                            ->label('Version')
                            ->badge(),
                        Infolists\Components\TextEntry::make('released_at')
                            ->label('Released on')
                            ->date(),
                        Infolists\Components\TextEntry::make('title'),
                    ])
                    ->columns(3),

                Infolists\Components\TextEntry::make('body')
                    ->label('What changed')
                    ->html()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('released_at', 'desc')
            ->recordUrl(fn (ReleaseNote $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('version')
                    ->label('Version')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('released_at')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('summary')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReleaseNotes::route('/'),
            'create' => Pages\CreateReleaseNote::route('/create'),
            'view' => Pages\ViewReleaseNote::route('/{record}'),
            'edit' => Pages\EditReleaseNote::route('/{record}/edit'),
        ];
    }
}
