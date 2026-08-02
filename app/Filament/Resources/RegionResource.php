<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RegionResource\Pages;
use App\Http\Controllers\Controller;
use App\Models\Region;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RegionResource extends Resource
{
    use Translatable;

    protected static ?string $model = Region::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Content';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->disk('r2')
                    ->directory('regions')
                    ->imageEditor()
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
                Forms\Components\Select::make('listing_region')
                    ->label('Matches listing region')
                    ->helperText('Which of the 6 political regions this destination belongs to — determines what gets shown when a traveler clicks this card.')
                    ->options(array_combine(config('kaia.regions'), config('kaia.regions')))
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
                    ->getStateUsing(fn (Region $record): ?string => $record->image ? Controller::resolveMediaUrl($record->image) : null)
                    ->square(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('listing_region')
                    ->label('Region'),
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
            'index' => Pages\ListRegions::route('/'),
            'create' => Pages\CreateRegion::route('/create'),
            'edit' => Pages\EditRegion::route('/{record}/edit'),
        ];
    }
}
