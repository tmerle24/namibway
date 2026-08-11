<?php

namespace App\Filament\Resources;

use App\Enums\AmenityCategory;
use App\Enums\AmenityScope;
use App\Filament\Resources\AmenityResource\Pages;
use App\Models\Amenity;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The shared amenity catalogue, curated by the content team.
 *
 * Deliberately not a partner screen. One catalogue is the only thing that
 * makes two rooms comparable, and a catalogue anybody can extend is one where
 * "WiFi", "Wi-Fi" and "wifi" are three different amenities within a month. A
 * property that needs something missing asks, and everybody gets it.
 *
 * Retiring beats deleting: an amenity in use is attached to rooms, and
 * removing it would quietly rewrite what those rooms claim to have. Untick
 * "in use" and it disappears from the picker while the rooms that have it keep
 * saying so.
 */
class AmenityResource extends Resource
{
    protected static ?string $model = Amenity::class;

    // Not the sparkles mark — that one means "AI" everywhere else in the panel
    // (Data Enrichment), and an amenity catalogue is hand-curated by definition.
    protected static ?string $navigationIcon = 'heroicon-o-wifi';

    // A vocabulary the rest of the content is written against, like regions and
    // cities — not something the content team edits day to day.
    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Amenities';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(120)
                ->helperText('What a guest reads. Written once here rather than forty times across properties.'),

            TextInput::make('code')
                ->required()
                ->maxLength(60)
                ->unique(ignoreRecord: true)
                ->helperText('Stable identifier, lower_snake_case. Never change one that is in use — it is what an import and, later, a channel mapping match on.'),

            Select::make('category')
                ->options(collect(AmenityCategory::inReadingOrder())
                    ->mapWithKeys(fn (AmenityCategory $case) => [$case->value => $case->label()])
                    ->all())
                ->required()
                ->native(false),

            Select::make('scope')
                ->label('Asked about')
                ->options(collect(AmenityScope::cases())
                    ->mapWithKeys(fn (AmenityScope $case) => [$case->value => $case->label()])
                    ->all())
                ->default(AmenityScope::Room->value)
                ->required()
                ->native(false)
                ->helperText('A pool belongs to the lodge, a mosquito net to the chalet, and Wi-Fi to both — often with different answers.'),

            TextInput::make('sort')
                ->numeric()
                ->default(0)
                ->helperText('Order within the category. Leave it alone unless something reads wrongly.'),

            Toggle::make('is_active')
                ->label('In use')
                ->default(true)
                ->helperText('Off hides it from the picker without touching the rooms that already have it.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('code')->badge()->color('gray')->searchable(),
                Tables\Columns\TextColumn::make('category')
                    ->formatStateUsing(fn (AmenityCategory $state): string => $state->label())
                    ->sortable(),
                Tables\Columns\TextColumn::make('scope')
                    ->label('Asked about')
                    ->formatStateUsing(fn (AmenityScope $state): string => $state->label()),
                Tables\Columns\TextColumn::make('listings_count')
                    ->label('Properties')
                    ->counts('listings')
                    ->sortable(),
                Tables\Columns\TextColumn::make('bookable_units_count')
                    ->label('Rooms')
                    ->counts('bookableUnits')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options(collect(AmenityCategory::inReadingOrder())
                        ->mapWithKeys(fn (AmenityCategory $case) => [$case->value => $case->label()])
                        ->all()),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('sort')
            ->paginated([50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAmenities::route('/'),
            'create' => Pages\CreateAmenity::route('/create'),
            'edit' => Pages\EditAmenity::route('/{record}/edit'),
        ];
    }
}
