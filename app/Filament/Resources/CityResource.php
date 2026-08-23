<?php

namespace App\Filament\Resources;

use App\Enums\CityType;
use App\Filament\Resources\CityResource\Pages;
use App\Filament\Support\PipelineImageResolver;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Destination;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * The address gazetteer — towns, villages and settlements, i.e. what a
 * listing's street address resolves to.
 *
 * Between 2026-08-18 and 2026-08-23 this also held parks, reserves and
 * landmarks and was labelled "Places", because a lodge standing in a park had
 * nowhere to be filed. Those are a different noun and have their own screen
 * now (see PlaceResource): a city is where post arrives, a place is where a
 * traveller goes. `Place` on a row here is the trip identity of the same town.
 */
class CityResource extends Resource
{
    protected static ?string $model = City::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Settings';

    /**
     * The areas a place can be filed under, id => name, sorted on the
     * translated name in PHP.
     *
     * @return array<int, string>
     */
    public static function areaOptions(): array
    {
        return Destination::query()
            ->get(['id', 'name'])
            ->sortBy(fn (Destination $destination): string => (string) $destination->name)
            ->mapWithKeys(fn (Destination $destination): array => [$destination->id => (string) $destination->name])
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->disk('r2')
                    ->directory('cities')
                    ->imageEditor()
                    ->fetchFileInformation(false)
                    ->getUploadedFileUsing(PipelineImageResolver::resolve(...))
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Forms\Set $set) => $set('slug', $state ? Str::slug($state) : null)),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\Select::make('region_id')
                    ->label('Region (political)')
                    ->helperText('Administrative only — the traveller never sees it. Carries the country.')
                    ->relationship('region', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('destination_id')
                    ->label('Area')
                    ->helperText('What the traveller is shown: "Onguma Nature Reserve (Etosha)". Leave empty for a place that is in no tourism area — a town simply stands for itself.')
                    // Options built in PHP rather than through relationship():
                    // a destination's name is a translations JSON column, and
                    // Postgres cannot order by one ("could not identify an
                    // ordering operator for type json"). Same reason below.
                    ->options(self::areaOptions(...))
                    ->searchable(),
                Forms\Components\Select::make('type')
                    ->label('Place type')
                    ->options(CityType::class)
                    ->required()
                    ->helperText('A park, reserve or landmark is a real place to file a lodge in — use it rather than the nearest town when the property is not in that town.'),
                Forms\Components\TextInput::make('population')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Settlements only — leave empty for a park, reserve or landmark.'),
                Forms\Components\TextInput::make('area_km2')
                    ->label('Area (km²)')
                    ->numeric()
                    ->minValue(0),
                Forms\Components\TextInput::make('lat')
                    ->label('Latitude')
                    ->numeric(),
                Forms\Components\TextInput::make('lng')
                    ->label('Longitude')
                    ->numeric(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->getStateUsing(fn (City $record): ?string => $record->image ? Controller::resolveMediaUrl($record->image) : null)
                    ->square(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('place.name')
                    ->label('Place')
                    ->placeholder('—')
                    ->tooltip('The trip identity of this town — what Kaia routes on. Empty means no traveller plans around it.'),
                Tables\Columns\TextColumn::make('destination.name')
                    ->label('Area')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('region.name')
                    ->label('Region')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('population')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('destination_id')
                    ->label('Area')
                    ->options(self::areaOptions(...)),
                Tables\Filters\SelectFilter::make('region_id')
                    ->label('Region')
                    ->relationship('region', 'name'),
                Tables\Filters\SelectFilter::make('type')
                    ->label('City type')
                    ->options(CityType::class),
                Tables\Filters\TernaryFilter::make('place_id')
                    ->label('Is a place')
                    ->placeholder('All')
                    ->trueLabel('Somewhere travellers plan around')
                    ->falseLabel('Address only')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('place_id'),
                        false: fn ($query) => $query->whereNull('place_id'),
                        blank: fn ($query) => $query,
                    ),
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
            'index' => Pages\ListCities::route('/'),
            'create' => Pages\CreateCity::route('/create'),
            'edit' => Pages\EditCity::route('/{record}/edit'),
        ];
    }
}
