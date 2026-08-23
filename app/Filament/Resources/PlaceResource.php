<?php

namespace App\Filament\Resources;

use App\Enums\PlaceType;
use App\Filament\Resources\PlaceResource\Pages;
use App\Filament\Support\PipelineImageResolver;
use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\Place;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Where a traveller goes — a national park, a private reserve, a landmark, and
 * the towns that are stops on a trip.
 *
 * Not a City, which is an address. Onguma has no street and no postcode, and a
 * camp standing on it is posted to Outjo, an hour away. Everything the trip
 * plan is built out of is a place: the day's location, the driving matrix, the
 * map. A city is what a street address resolves to, and `Place → Nearest town`
 * is the link between the two.
 *
 * A town appears in both tables on purpose. It is one dot on the map seen
 * twice — as an address and as a stop — and that is what keeps the matrix a
 * uniform place-to-place pair instead of a polymorphic city-or-place one.
 */
class PlaceResource extends Resource
{
    use Translatable;

    protected static ?string $model = Place::class;

    // Distinct from Cities (heroicon-o-building-office-2, a built-up address)
    // and from Destinations (heroicon-o-map-pin, the area above this).
    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Settings';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->disk('r2')
                    ->directory('places')
                    ->imageEditor()
                    ->fetchFileInformation(false)
                    ->getUploadedFileUsing(PipelineImageResolver::resolve(...))
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Exactly what a traveller and the trip plan call it — "Etosha National Park", "Swakopmund".'),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\Select::make('type')
                    ->options(PlaceType::class)
                    ->required()
                    ->helperText('"Town" is a place people also live in and has a city beside it; the rest are tourism locations.'),
                Forms\Components\Select::make('region_id')
                    ->label('Region')
                    ->relationship('region', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText('The political region — which administration this falls under, not what a traveller is shown.'),
                Forms\Components\Select::make('destination_id')
                    ->label('Area')
                    ->options(CityResource::areaOptions(...))
                    ->searchable()
                    ->nullable()
                    ->helperText('The name a traveller uses: Onguma and Ongava are both "Etosha". Leave empty where the place stands for itself.'),
                Forms\Components\Select::make('city_id')
                    ->label('Nearest town')
                    ->relationship('city', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Only a fallback for an address and for "what is near here" — a place is not *in* a city.'),
                Forms\Components\TextInput::make('lat')
                    ->label('Latitude')
                    ->numeric()
                    ->helperText('Decimal degrees, e.g. -19.1833. Without coordinates this place cannot be routed.'),
                Forms\Components\TextInput::make('lng')
                    ->label('Longitude')
                    ->numeric()
                    ->helperText('Decimal degrees, e.g. 15.9333'),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('slug')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->getStateUsing(fn (Place $record): ?string => $record->image ? Controller::resolveMediaUrl($record->image) : null)
                    ->square(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('destination.name')
                    ->label('Area')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('region.name')
                    ->label('Region')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('listings_count')
                    ->label('Listings')
                    ->counts('listings'),
                Tables\Columns\IconColumn::make('lat')
                    ->label('Routable')
                    ->boolean()
                    ->getStateUsing(fn (Place $record): bool => $record->lat !== null && $record->lng !== null)
                    ->tooltip('A place without coordinates is left out of the driving matrix and cannot appear in a plan.'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(PlaceType::class),
                Tables\Filters\SelectFilter::make('destination_id')
                    ->label('Area')
                    ->options(fn (): array => Destination::query()
                        ->get(['id', 'name'])
                        ->sortBy(fn (Destination $destination): string => (string) $destination->name)
                        ->mapWithKeys(fn (Destination $destination): array => [$destination->id => (string) $destination->name])
                        ->all()),
                Tables\Filters\SelectFilter::make('region_id')
                    ->label('Region')
                    ->relationship('region', 'name'),
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
            'index' => Pages\ListPlaces::route('/'),
            'create' => Pages\CreatePlace::route('/create'),
            'edit' => Pages\EditPlace::route('/{record}/edit'),
        ];
    }
}
