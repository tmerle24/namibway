<?php

namespace App\Filament\Partner\Resources;

use App\Enums\GuestKind;
use App\Filament\Partner\Resources\GuestCategoryResource\Pages;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\GuestCategory;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adults, children, infants — and what each of them pays.
 *
 * Only properties that price by guests ever need these, which is why nothing
 * is created until somebody asks. The list starts empty and offers the usual
 * set in one click; see GuestCategoryDefaults.
 */
class GuestCategoryResource extends Resource
{
    protected static ?string $model = GuestCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Guest types';

    protected static ?string $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'guest-types';

    public static function canAccess(): bool
    {
        return filled(auth()->user()?->partner_id);
    }

    /** @return Builder<GuestCategory> */
    public static function getEloquentQuery(): Builder
    {
        $property = app(SelectedProperty::class)->current();

        return parent::getEloquentQuery()
            ->where('listing_id', $property?->id ?? 0)
            ->orderBy('sort')
            ->orderBy('id');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Who this is')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(60)
                            ->placeholder('Adult, Child, Infant, Teenager …'),
                        TextInput::make('code')->label('Code')->required()->maxLength(20),
                        Select::make('kind')
                            ->label('Counts as')
                            ->options(collect(GuestKind::cases())
                                ->mapWithKeys(fn (GuestKind $kind) => [$kind->value => $kind->label()])
                                ->all())
                            ->default(GuestKind::Adult->value)
                            ->required()
                            ->helperText('Only used to work out who is travelling alone.'),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('min_age')
                            ->label('From age')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(120)
                            ->helperText('Leave empty if there is no lower bound.'),
                        TextInput::make('max_age')
                            ->label('To age')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(120)
                            ->helperText('Leave empty for "and over".'),
                    ]),
                ]),

            Section::make('What they pay')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('charge_percent')
                            ->label('Share of the adult price')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(200)
                            ->suffix('%')
                            ->default(100)
                            ->required()
                            ->helperText('100% is the full rate. 0% is free.'),
                        Toggle::make('counts_toward_occupancy')
                            ->label('Counts as an occupant')
                            ->default(true)
                            ->helperText('Turn off for a cot: an infant that does not move a room from two guests to three.'),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('sort')->label('Order')->numeric()->default(0),
                        Toggle::make('is_active')->label('In use')->default(true),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name'),
                Tables\Columns\TextColumn::make('code')->label('Code'),
                Tables\Columns\TextColumn::make('kind')
                    ->label('Counts as')
                    ->formatStateUsing(fn (GuestKind $state): string => $state->label()),
                Tables\Columns\TextColumn::make('id')
                    ->label('Ages')
                    ->formatStateUsing(fn (GuestCategory $record): string => $record->ageLabel() ?? 'any'),
                Tables\Columns\TextColumn::make('charge_percent')
                    ->label('Pays')
                    ->formatStateUsing(fn (GuestCategory $record): string => $record->percentLabel()),
                Tables\Columns\IconColumn::make('counts_toward_occupancy')->label('Occupant')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->emptyStateHeading('No guest types yet')
            ->emptyStateDescription('Only needed when a rate is priced per person or by the number of guests.')
            ->actions([Tables\Actions\EditAction::make()])
            ->paginated(false);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGuestCategories::route('/'),
            'create' => Pages\CreateGuestCategory::route('/create'),
            'edit' => Pages\EditGuestCategory::route('/{record}/edit'),
        ];
    }
}
