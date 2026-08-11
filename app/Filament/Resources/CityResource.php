<?php

namespace App\Filament\Resources;

use App\Enums\SettlementType;
use App\Filament\Resources\CityResource\Pages;
use App\Filament\Support\PipelineImageResolver;
use App\Http\Controllers\Controller;
use App\Models\City;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CityResource extends Resource
{
    protected static ?string $model = City::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Settings';

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
                    ->label('Region')
                    ->relationship('region', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('type')
                    ->options(SettlementType::class)
                    ->required(),
                Forms\Components\TextInput::make('population')
                    ->numeric()
                    ->minValue(0),
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
                Tables\Columns\TextColumn::make('region.name')
                    ->label('Region')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('population')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('region_id')
                    ->label('Region')
                    ->relationship('region', 'name'),
                Tables\Filters\SelectFilter::make('type')
                    ->options(SettlementType::class),
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
