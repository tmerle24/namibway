<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DestinationResource\Pages;
use App\Filament\Support\PipelineImageResolver;
use App\Http\Controllers\Controller;
use App\Models\Destination;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * A destination is two things at once, and both are deliberate: the card a
 * traveller sees on the homepage, and — since 2026-08-19 — the **area** places
 * are grouped under (Places → Area). That is why the card finally shows what it
 * promises: "Etosha" used to filter on the political region, which pulled in
 * the Skeleton Coast and left every lodge on Onguma out.
 *
 * An area that should group places without appearing as a card is simply left
 * unpublished — the grouping does not depend on `is_published`.
 */
class DestinationResource extends Resource
{
    use Translatable;

    protected static ?string $model = Destination::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Settings';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->disk('r2')
                    ->directory('regions')
                    ->imageEditor()
                    ->fetchFileInformation(false)
                    ->getUploadedFileUsing(PipelineImageResolver::resolve(...))
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\Textarea::make('blurb')
                    ->label('Blurb')
                    ->helperText('Short teaser shown on the Explore card, e.g. "Windhoek and the central highlands — most itineraries start here."')
                    ->columnSpanFull(),
                Forms\Components\Select::make('region_id')
                    ->label('Matches region')
                    ->relationship('region', 'name')
                    ->helperText('Which political region this destination belongs to — determines what gets shown when a traveler clicks this card.')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('lat')
                    ->label('Latitude')
                    ->numeric()
                    ->nullable()
                    ->helperText('Decimal degrees, e.g. -22.5597'),
                Forms\Components\TextInput::make('lng')
                    ->label('Longitude')
                    ->numeric()
                    ->nullable()
                    ->helperText('Decimal degrees, e.g. 17.0832'),
                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),
                Forms\Components\Toggle::make('is_published')
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    // Not disk('public'): image can be on either 'r2' (current default)
                    // or 'public' (rows uploaded before the r2 switch) — resolveMediaUrl
                    // already knows how to check both.
                    ->getStateUsing(fn (Destination $record): ?string => $record->image ? Controller::resolveMediaUrl($record->image) : null)
                    ->square(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('region.name')
                    ->label('Region'),
                Tables\Columns\TextColumn::make('cities_count')
                    ->label('Places')
                    ->counts('cities')
                    ->tooltip('How many places are filed under this area — what the card actually shows.'),
                Tables\Columns\TextColumn::make('sort_order')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_published')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDestinations::route('/'),
            'create' => Pages\CreateDestination::route('/create'),
            'edit' => Pages\EditDestination::route('/{record}/edit'),
        ];
    }
}
