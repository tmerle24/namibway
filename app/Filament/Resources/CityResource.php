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
 * The place gazetteer a listing is filed against — towns and villages, and
 * since 2026-08-18 the tourism areas that are not settlements at all (Etosha,
 * Onguma, Sossusvlei). It is called "Places" everywhere a person can read it,
 * because "Cities" is what told the content team a lodge in a national park
 * had nowhere to go; the model and table keep their names.
 */
class CityResource extends Resource
{
    protected static ?string $model = City::class;

    // Keeps the city icon although the resource now also holds parks and
    // reserves: heroicon-o-map-pin already means Destinations in this panel,
    // and one icon means one thing here.
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Places';

    protected static ?string $modelLabel = 'place';

    protected static ?string $pluralModelLabel = 'places';

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
                    ->label('Place type')
                    ->options(CityType::class),
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
