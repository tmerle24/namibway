<?php

namespace App\Filament\Resources;

use App\Enums\FuelType;
use App\Enums\SupplyService;
use App\Filament\Resources\SupplyPointResource\Pages;
use App\Models\City;
use App\Models\Place;
use App\Models\SupplyPoint;
use App\Support\OpeningHours;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Where a traveller can fill up, and where they can buy food to cook.
 *
 * A short table by design: it is not a directory of Namibian filling stations,
 * it is the data behind one line in the trip plan — "last fuel for ≈240 km".
 * The trip plan names a row here only when it is the last chance before a long
 * stretch without (App\Services\Routing\SupplyStopFinder), so the rows that
 * earn their keep are the ones on the empty roads: Solitaire, Kamanjab, Uis,
 * Sesfontein. A tenth Windhoek forecourt changes nothing a traveller sees.
 *
 * The two columns that decide whether the answer is any use are *when it is
 * open* and *which pump it has*. The third is `verified_at`: a filling station
 * is not a fact that stays true, and a row nobody has checked since it was
 * typed says so rather than pretending.
 */
class SupplyPointResource extends Resource
{
    use Translatable;

    protected static ?string $model = SupplyPoint::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Supply points';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('What it is')
                    ->description('The name as it is signposted. Nobody translates a filling station, so this one is not translatable — the note at the bottom is.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('"Solitaire", "Engen Kamanjab", "Spar Outjo".'),
                        Forms\Components\TextInput::make('slug')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Left empty it is made from the name.'),
                        Forms\Components\CheckboxList::make('services')
                            ->options(SupplyService::class)
                            // The cast hands back enum instances and the
                            // options are keyed by their stored values, so
                            // without this an existing row opens with nothing
                            // ticked and saving it empties the column.
                            ->formatStateUsing(fn (mixed $state): array => collect($state)
                                ->map(fn (mixed $value): string => $value instanceof SupplyService ? $value->value : (string) $value)
                                ->all())
                            ->required()
                            ->columns(3)
                            ->columnSpanFull()
                            ->helperText('What a traveller can actually get here. Tick Groceries only where somebody could stock up for a few days of self-catering — a shelf of crisps beside the till is not a supermarket, and naming one as the last shop before three nights of cooking is worse than naming nothing.'),
                        Forms\Components\CheckboxList::make('fuel_types')
                            ->label('Pumps')
                            ->options(FuelType::class)
                            ->formatStateUsing(fn (mixed $state): array => collect($state)
                                ->map(fn (mixed $value): string => $value instanceof FuelType ? $value->value : (string) $value)
                                ->all())
                            ->columns(2)
                            ->columnSpanFull()
                            ->visible(fn (Forms\Get $get): bool => in_array(SupplyService::Fuel->value, (array) $get('services'), true))
                            ->helperText('Leave both unticked where nobody has checked — that means "not recorded", not "neither". Most hire cars are petrol and most campers are diesel, and a rural station is usually out of one rather than both.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('When it is open')
                    ->description('OpenStreetMap opening_hours syntax, which is what every source this will ever be filled from already speaks.')
                    ->schema([
                        Forms\Components\TextInput::make('opening_hours')
                            ->label('Opening hours')
                            ->maxLength(255)
                            // The field validates against the parser itself,
                            // so a string the trip plan could not read is a
                            // string nobody can save.
                            ->rule(fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                if (is_string($value) && trim($value) !== '' && ! OpeningHours::isValid($value)) {
                                    $fail('This cannot be read. Use 24/7, or day-and-time rules like "Mo-Fr 07:00-18:00; Sa 08:00-13:00; Su off".');
                                }
                            })
                            ->helperText('24/7 · Mo-Fr 07:00-18:00; Sa 08:00-13:00; Su off · Mo-Su 06:00-22:00. Month ranges, holidays and sunrise/sunset are not read, and are refused rather than half-understood — a traveller drives on what this says. Leave empty where nobody has checked.')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Where it is')
                    ->description('Coordinates are what put it on a route. Without them the row is a note to ourselves — the town it is in is precise enough for a rule that measures gaps in hundreds of kilometres, so copying the town\'s own coordinates is a perfectly good answer.')
                    ->schema([
                        Forms\Components\Select::make('city_id')
                            ->label('Town')
                            ->relationship('city', 'name')
                            ->searchable()
                            ->preload()
                            ->optionsLimit(300)
                            ->getOptionLabelFromRecordUsing(fn (City $record): string => (string) $record->name),
                        Forms\Components\Select::make('place_id')
                            ->label('Place')
                            ->relationship('place', 'slug')
                            ->getOptionLabelFromRecordUsing(fn (Place $record): string => (string) $record->name)
                            ->searchable(['slug'])
                            ->preload()
                            ->optionsLimit(300)
                            ->helperText('For the ones that are in no town at all — Okaukuejo sells fuel and is inside Etosha.'),
                        Forms\Components\TextInput::make('lat')
                            ->label('Latitude')
                            ->numeric()
                            ->helperText('Decimal degrees, e.g. -23.8983'),
                        Forms\Components\TextInput::make('lng')
                            ->label('Longitude')
                            ->numeric()
                            ->helperText('Decimal degrees, e.g. 16.0031'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('How much we trust it')
                    ->schema([
                        Forms\Components\Textarea::make('note')
                            ->label('Note')
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('One line a traveller reads: "cash only", "diesel often out", "the last pump before the Kaokoveld".'),
                        Forms\Components\DateTimePicker::make('verified_at')
                            ->label('Last verified')
                            ->helperText('When a human last confirmed this still exists and still sells what it says. Empty means nobody has.'),
                        Forms\Components\Toggle::make('is_published')
                            ->helperText('Nothing here auto-publishes.'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                // The enum instances themselves, so each badge takes its own
                // label and colour from the enum rather than printing a value.
                Tables\Columns\TextColumn::make('services')
                    ->badge()
                    ->getStateUsing(fn (SupplyPoint $record): array => $record->serviceList()->all()),
                Tables\Columns\TextColumn::make('fuel_types')
                    ->label('Pumps')
                    ->badge()
                    ->placeholder('—')
                    ->getStateUsing(fn (SupplyPoint $record): array => $record->fuelTypeList()->all()),
                Tables\Columns\TextColumn::make('city.name')
                    ->label('Town')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('place.name')
                    ->label('Place')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('opening_hours')
                    ->label('Open')
                    ->placeholder('not recorded')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('lat')
                    ->label('Findable')
                    ->boolean()
                    ->getStateUsing(fn (SupplyPoint $record): bool => $record->isRoutable())
                    ->tooltip('Without coordinates it can never be named on a route.'),
                Tables\Columns\TextColumn::make('verified_at')
                    ->label('Verified')
                    ->date()
                    ->placeholder('never')
                    ->sortable()
                    ->tooltip('A filling station is not a fact that stays true.'),
                Tables\Columns\IconColumn::make('is_published')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('services')
                    ->label('Sells')
                    ->options(SupplyService::class)
                    ->query(fn ($query, array $data) => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereJsonContains('services', $data['value'])),
                Tables\Filters\TernaryFilter::make('is_published'),
                Tables\Filters\TernaryFilter::make('lat')
                    ->label('Findable')
                    ->placeholder('All')
                    ->trueLabel('Has coordinates')
                    ->falseLabel('Missing coordinates')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('lat')->whereNotNull('lng'),
                        false: fn ($query) => $query->whereNull('lat')->orWhereNull('lng'),
                        blank: fn ($query) => $query,
                    ),
                Tables\Filters\TernaryFilter::make('verified_at')
                    ->label('Verified')
                    ->placeholder('All')
                    ->trueLabel('Somebody checked')
                    ->falseLabel('Never checked')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('verified_at'),
                        false: fn ($query) => $query->whereNull('verified_at'),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupplyPoints::route('/'),
            'create' => Pages\CreateSupplyPoint::route('/create'),
            'edit' => Pages\EditSupplyPoint::route('/{record}/edit'),
        ];
    }
}
