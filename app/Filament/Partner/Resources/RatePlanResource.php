<?php

namespace App\Filament\Partner\Resources;

use App\Enums\BoardBasis;
use App\Enums\PricingStrategy;
use App\Enums\RatePlanEligibility;
use App\Filament\Partner\Resources\RatePlanResource\Pages;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\RatePlan;
use App\Support\CountrySettings;
use App\Support\Money;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The products a property sells its rooms under.
 *
 * A rate plan is where board basis, cancellation terms, who may book it and —
 * the part that changes every price on the screen — how a night becomes an
 * amount all live. See BOOKING_SYSTEM.md.
 *
 * Scoped to the selected property rather than to the partner, because that is
 * what every other lodge-facing screen shows and a rate plan belongs to one
 * property: NWR's twenty camps do not share a tariff.
 */
class RatePlanResource extends Resource
{
    protected static ?string $model = RatePlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Rate plans';

    protected static ?string $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'rate-plans';

    public static function canAccess(): bool
    {
        return filled(auth()->user()?->partner_id);
    }

    /** @return Builder<RatePlan> */
    public static function getEloquentQuery(): Builder
    {
        $property = app(SelectedProperty::class)->current();

        return parent::getEloquentQuery()
            // No property selected means no rate plans, not every partner's.
            ->where('listing_id', $property?->id ?? 0)
            ->orderByDesc('is_default')
            ->orderBy('sort')
            ->orderBy('id');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('What this rate is')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('Standard rate, Namibian resident, Non-refundable …'),
                        TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->maxLength(20)
                            ->helperText('Short, for rate sheets and channel managers. STD, RES, DBB …'),
                        Select::make('board_basis')
                            ->label('Board')
                            ->options(collect(BoardBasis::cases())
                                ->mapWithKeys(fn (BoardBasis $basis) => [$basis->value => $basis->label()])
                                ->all())
                            ->placeholder('Not stated')
                            // Left blank rather than defaulted: claiming a rate
                            // includes breakfast when nobody said so is a claim
                            // a guest arrives with.
                            ->helperText('Leave empty if this rate does not say.'),
                        Select::make('eligibility')
                            ->label('Who may book it')
                            ->options(collect(RatePlanEligibility::cases())
                                ->mapWithKeys(fn (RatePlanEligibility $case) => [$case->value => $case->label()])
                                ->all())
                            ->default(RatePlanEligibility::Public->value)
                            ->required(),
                    ]),
                    Textarea::make('description')->label('Description')->rows(2)->maxLength(500),
                ]),

            Section::make('How it is priced')
                ->description('This decides what the number on the calendar means.')
                ->schema([
                    Select::make('pricing_strategy')
                        ->label('Priced')
                        ->options(collect(PricingStrategy::cases())
                            ->mapWithKeys(fn (PricingStrategy $case) => [$case->value => $case->label()])
                            ->all())
                        ->descriptions(collect(PricingStrategy::cases())
                            ->mapWithKeys(fn (PricingStrategy $case) => [$case->value => $case->description()])
                            ->all())
                        ->default(PricingStrategy::PerUnit->value)
                        ->required()
                        ->live(),

                    Grid::make(2)
                        ->schema([
                            TextInput::make('single_supplement_amount')
                                ->label('Single supplement')
                                ->numeric()
                                ->minValue(0)
                                ->prefix(fn (): string => Money::symbol(static::currency()))
                                ->helperText('Per night, when one person has the room to themselves.'),
                            TextInput::make('single_supplement_percent')
                                ->label('… or as a percentage')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(500)
                                ->suffix('%')
                                ->helperText('Of the per-person rate. Ignored when an amount is filled in above.'),
                        ])
                        // Only per-person-sharing has a single supplement; on
                        // the others it is a field with no meaning.
                        ->visible(fn (Get $get): bool => $get('pricing_strategy') === PricingStrategy::PerPersonSharing->value),
                ]),

            Section::make('Terms')
                ->schema([
                    Grid::make(3)->schema([
                        Toggle::make('is_refundable')->label('Refundable')->default(true),
                        TextInput::make('cancellation_days')
                            ->label('Free cancellation until')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(365)
                            ->suffix('days before arrival'),
                        Toggle::make('is_active')->label('On sale')->default(true),
                    ]),
                    Grid::make(2)->schema([
                        Toggle::make('is_default')
                            ->label('The default rate')
                            ->helperText('What a booking uses when nobody chooses. One per property.'),
                        TextInput::make('sort')->label('Order')->numeric()->default(0),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('code')->label('Code'),
                Tables\Columns\TextColumn::make('board_basis')
                    ->label('Board')
                    ->formatStateUsing(fn (?BoardBasis $state): string => $state?->label() ?? '—'),
                Tables\Columns\TextColumn::make('pricing_strategy')
                    ->label('Priced')
                    ->formatStateUsing(fn (PricingStrategy $state): string => $state->label()),
                Tables\Columns\TextColumn::make('eligibility')
                    ->label('For')
                    ->formatStateUsing(fn (RatePlanEligibility $state): string => $state->label()),
                Tables\Columns\IconColumn::make('is_default')->label('Default')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('On sale')->boolean(),
            ])
            ->emptyStateHeading('No rate plans yet')
            ->emptyStateDescription('Every property sells at least one product. Until one is created, rooms are sold at their own rate.')
            ->actions([Tables\Actions\EditAction::make()])
            ->paginated(false);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRatePlans::route('/'),
            'create' => Pages\CreateRatePlan::route('/create'),
            'edit' => Pages\EditRatePlan::route('/{record}/edit'),
        ];
    }

    private static function currency(): string
    {
        $property = app(SelectedProperty::class)->current();

        return $property === null
            ? CountrySettings::forCountry(null)->currency()
            : CountrySettings::for($property)->currency();
    }
}
