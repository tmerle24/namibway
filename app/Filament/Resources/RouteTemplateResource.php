<?php

namespace App\Filament\Resources;

use App\Enums\RouteTripType;
use App\Filament\Resources\RouteTemplateResource\Pages;
use App\Models\RouteTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RouteTemplateResource extends Resource
{
    use Translatable;

    protected static ?string $model = RouteTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Route Templates';

    protected static ?string $navigationGroup = 'Settings';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\Select::make('trip_type')
                    ->options(RouteTripType::class)
                    ->required(),
                Forms\Components\TextInput::make('min_nights')
                    ->label('Min nights')
                    ->helperText('Total trip length this route is designed for — used to match it against a traveler\'s requested duration.')
                    ->numeric()
                    ->required()
                    ->minValue(1),
                Forms\Components\TextInput::make('max_nights')
                    ->label('Max nights')
                    ->numeric()
                    ->required()
                    ->minValue(1),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->helperText('Admin-facing context (e.g. "good for photography focus, avoid Jan–Feb rains") — also shown to Kaia as an extra signal for matching a traveler\'s interests.')
                    ->columnSpanFull(),
                Forms\Components\Repeater::make('stops')
                    ->relationship()
                    ->label('Stops')
                    ->helperText('The loop body between the Windhoek start/end days, in visiting order — do not repeat the start/end region here, Kaia adds that automatically.')
                    ->schema([
                        Forms\Components\Select::make('region')
                            ->options(array_combine(config('kaia.regions'), config('kaia.regions')))
                            ->required(),
                        Forms\Components\TextInput::make('min_nights')
                            ->label('Min nights')
                            ->numeric()
                            ->required()
                            ->default(1)
                            ->minValue(1),
                        Forms\Components\TextInput::make('max_nights')
                            ->label('Max nights')
                            ->numeric()
                            ->required()
                            ->default(1)
                            ->minValue(1),
                        Forms\Components\TextInput::make('highlights')
                            ->label('Highlights')
                            ->helperText('e.g. "Spitzkoppe, Skeleton Coast, Swakopmund"')
                            ->maxLength(255),
                    ])
                    ->columns(4)
                    ->orderColumn('sort_order')
                    ->addActionLabel('Add stop')
                    ->itemLabel(fn (array $state): ?string => $state['region'] ?? null)
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->columnSpanFull(),
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
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('trip_type')
                    ->badge(),
                Tables\Columns\TextColumn::make('min_nights')
                    ->label('Nights')
                    ->formatStateUsing(fn (RouteTemplate $record): string => "{$record->min_nights}–{$record->max_nights}"),
                Tables\Columns\TextColumn::make('stops_count')
                    ->label('Stops')
                    ->counts('stops'),
                Tables\Columns\TextColumn::make('sort_order')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_published')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('trip_type')
                    ->options(RouteTripType::class),
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
            'index' => Pages\ListRouteTemplates::route('/'),
            'create' => Pages\CreateRouteTemplate::route('/create'),
            'edit' => Pages\EditRouteTemplate::route('/{record}/edit'),
        ];
    }
}
