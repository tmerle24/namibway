<?php

namespace App\Filament\Partner\Resources;

use App\Enums\ChargeBase;
use App\Enums\ChargeBasis;
use App\Enums\ChargeKind;
use App\Filament\Partner\Resources\ChargeResource\Pages;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\Charge;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a property adds to a price: VAT, the tourism levy, a park permit.
 *
 * The screen's whole job is to make one question unmissable — **is this
 * already in your rates, or is it added on top?** Everything else here is a
 * number and a name; that one answer changes what a guest is quoted, and a
 * lodge that gets it wrong either loses 15% or over-charges every booking.
 */
class ChargeResource extends Resource
{
    protected static ?string $model = Charge::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Taxes and fees';

    protected static ?string $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'taxes';

    public static function canAccess(): bool
    {
        return filled(auth()->user()->partner_id ?? null);
    }

    /** @return Builder<Charge> */
    public static function getEloquentQuery(): Builder
    {
        $property = app(SelectedProperty::class)->current();

        /** @var Builder<Charge> $query */
        $query = parent::getEloquentQuery()
            ->where('listing_id', $property->id ?? 0)
            ->orderBy('sort')
            ->orderBy('id');

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('What it is')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(80)
                            ->placeholder('VAT, Tourism levy, Conservancy fee …')
                            ->helperText('This is what a guest reads on the invoice.'),
                        Select::make('kind')
                            ->label('Type')
                            ->options(collect(ChargeKind::cases())
                                ->mapWithKeys(fn (ChargeKind $kind) => [$kind->value => $kind->label()])
                                ->all())
                            ->default(ChargeKind::Tax->value)
                            ->required()
                            ->helperText(fn (Get $get): string => ChargeKind::tryFrom((string) $get('kind'))?->description() ?? ''),
                    ]),
                ]),

            Section::make('How it is worked out')
                ->schema([
                    Radio::make('basis')
                        ->label('Basis')
                        ->options(collect(ChargeBasis::cases())
                            ->mapWithKeys(fn (ChargeBasis $basis) => [$basis->value => $basis->label()])
                            ->all())
                        ->descriptions(collect(ChargeBasis::cases())
                            ->mapWithKeys(fn (ChargeBasis $basis) => [$basis->value => $basis->description()])
                            ->all())
                        ->default(ChargeBasis::Percentage->value)
                        ->live()
                        ->required(),

                    Grid::make(2)->schema([
                        TextInput::make('rate')
                            ->label(fn (Get $get): string => ChargeBasis::tryFrom((string) $get('basis'))?->isPercentage() === true
                                ? 'Percentage'
                                : 'Amount')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->suffix(fn (Get $get): ?string => ChargeBasis::tryFrom((string) $get('basis'))?->isPercentage() === true ? '%' : null)
                            ->helperText('Namibian VAT is 15, the NTB tourism levy is 2.'),

                        Toggle::make('charges_children')
                            ->label('Children are charged too')
                            ->default(true)
                            ->visible(fn (Get $get): bool => $get('basis') === ChargeBasis::PerPersonPerNight->value)
                            ->helperText('Off means only adults count — the usual rule for a park permit.'),
                    ]),

                    Select::make('base')
                        ->label('Worked out from')
                        ->options(collect(ChargeBase::cases())
                            ->mapWithKeys(fn (ChargeBase $base) => [$base->value => $base->label()])
                            ->all())
                        ->default(ChargeBase::StayOnly->value)
                        ->visible(fn (Get $get): bool => $get('basis') === ChargeBasis::Percentage->value)
                        ->helperText('Leave this as the stay unless VAT has to sit on top of a levy '
                            .'you pass on. Then put the levy above this charge and choose the second option.'),
                ]),

            Section::make('Included or added')
                ->description('The one answer that changes what a guest is quoted.')
                ->schema([
                    Toggle::make('is_included')
                        ->label('Already inside my rates')
                        ->helperText('On: your rates contain this, and the guest pays no more — it is '
                            .'named on the invoice and nothing else changes. Off: it is added on top of '
                            .'every booking.'),
                ]),

            Section::make('When it applies')
                ->collapsed()
                ->schema([
                    Grid::make(3)->schema([
                        DatePicker::make('valid_from')
                            ->label('From')
                            ->helperText('Empty means it has always applied.'),
                        DatePicker::make('valid_until')
                            ->label('Until')
                            ->helperText('Empty means until further notice.'),
                        TextInput::make('sort')
                            ->label('Order')
                            ->numeric()
                            ->default(0)
                            ->helperText('The order on the invoice. Lower first.'),
                    ]),

                    Toggle::make('is_active')->label('In use')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->weight('semibold'),
                Tables\Columns\TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (ChargeKind $state): string => $state->label()),
                Tables\Columns\TextColumn::make('rate')
                    ->label('Rate')
                    ->formatStateUsing(fn (float $state, Charge $record): string => $record->basis->isPercentage()
                        ? rtrim(rtrim(number_format($state, 2), '0'), '.').'%'
                        : number_format($state, 2)),
                Tables\Columns\TextColumn::make('basis')
                    ->label('Worked out')
                    ->formatStateUsing(fn (ChargeBasis $state): string => $state->label()),
                Tables\Columns\IconColumn::make('is_included')
                    ->label('In the rate')
                    ->boolean()
                    ->tooltip('On: already inside your rates. Off: added on top.'),
                Tables\Columns\IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('No taxes or fees yet')
            ->emptyStateDescription('Add VAT here if your rates do not already include it, '
                .'along with anything a guest is charged on top — a levy, a park permit, a conservancy fee.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCharges::route('/'),
            'create' => Pages\CreateCharge::route('/create'),
            'edit' => Pages\EditCharge::route('/{record}/edit'),
        ];
    }
}
