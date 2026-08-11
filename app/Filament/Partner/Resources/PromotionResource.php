<?php

namespace App\Filament\Partner\Resources;

use App\Enums\DiscountType;
use App\Filament\Partner\Resources\PromotionResource\Pages;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\BookableUnit;
use App\Models\Promotion;
use App\Models\RatePlan;
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
 * Offers a property runs.
 *
 * Two windows and they are not the same window: when the guest is *there*, and
 * when they *booked*. An early bird is a wide stay window with a narrow
 * booking window; a last-minute deal is the reverse. A screen offering only
 * one of them can express neither, which is why both are on it even though it
 * is one more thing to read.
 */
class PromotionResource extends Resource
{
    protected static ?string $model = Promotion::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Offers';

    protected static ?string $navigationGroup = 'Setup';

    protected static ?string $slug = 'offers';

    public static function canAccess(): bool
    {
        return filled(auth()->user()?->partner_id);
    }

    /** @return Builder<Promotion> */
    public static function getEloquentQuery(): Builder
    {
        $property = app(SelectedProperty::class)->current();

        /** @var Builder<Promotion> $query */
        $query = parent::getEloquentQuery()
            ->where('listing_id', $property->id ?? 0)
            ->orderByDesc('is_active')
            ->orderBy('id');

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('The offer')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('Green season special, Stay 4 pay 3 …'),
                        TextInput::make('code')
                            ->label('Code')
                            ->maxLength(40)
                            ->helperText('Leave empty and the offer applies by itself to every booking that fits. Fill it in and it only applies when somebody types it.'),
                    ]),

                    Radio::make('discount_type')
                        ->label('Takes off')
                        ->options(collect(DiscountType::cases())
                            ->mapWithKeys(fn (DiscountType $case) => [$case->value => $case->label()])
                            ->all())
                        ->descriptions(collect(DiscountType::cases())
                            ->mapWithKeys(fn (DiscountType $case) => [$case->value => $case->description()])
                            ->all())
                        ->default(DiscountType::Percentage->value)
                        ->required()
                        ->live(),

                    TextInput::make('discount_value')
                        ->label(fn (Get $get): string => match ($get('discount_type')) {
                            DiscountType::FreeNights->value => 'How many nights are free',
                            DiscountType::FixedAmount->value => 'Amount off',
                            default => 'Percent off',
                        })
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->helperText(fn (Get $get): string => $get('discount_type') === DiscountType::FreeNights->value
                            ? 'With a minimum stay below: "stay 4, pay 3" is a minimum of 4 nights and 1 free night. The cheapest nights are the free ones.'
                            : 'Applied to the whole stay after it has been priced.'),
                ]),

            Section::make('When it applies')
                ->schema([
                    Grid::make(2)->schema([
                        DatePicker::make('stay_from')->label('Staying from')->native(false)->displayFormat('D, d M Y'),
                        DatePicker::make('stay_until')->label('Staying until')->native(false)->displayFormat('D, d M Y'),
                        DatePicker::make('booked_from')->label('Booked from')->native(false)->displayFormat('D, d M Y'),
                        DatePicker::make('booked_until')->label('Booked until')->native(false)->displayFormat('D, d M Y'),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('min_nights')
                            ->label('Minimum nights')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(60),
                        TextInput::make('max_uses')
                            ->label('Times it can be used')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Empty is unlimited.'),
                    ]),
                ])
                ->description('Empty dates mean no limit. The whole stay has to fall inside the staying window — a stay that straddles the end of an offer does not get half of it.'),

            Section::make('What it applies to')
                ->description('Leave both empty for everything, which is what "20% off in November" usually means.')
                ->schema([
                    Select::make('ratePlans')
                        ->label('Only these rates')
                        ->relationship('ratePlans', 'name')
                        ->getOptionLabelFromRecordUsing(fn (RatePlan $record): string => $record->label())
                        ->multiple()
                        ->preload(),
                    Select::make('bookableUnits')
                        ->label('Only these room types')
                        ->relationship('bookableUnits', 'name')
                        ->getOptionLabelFromRecordUsing(fn (BookableUnit $record): string => $record->name.' ('.$record->code.')')
                        ->multiple()
                        ->preload(),
                    Toggle::make('is_active')->label('Running')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('code')->badge()->color('gray')->placeholder('Public'),
                Tables\Columns\TextColumn::make('discount_value')
                    ->label('Takes off')
                    ->formatStateUsing(fn (Promotion $record): string => $record->label()),
                Tables\Columns\TextColumn::make('stay_from')
                    ->label('Staying')
                    ->formatStateUsing(fn (Promotion $record): string => trim(
                        ($record->stay_from?->isoFormat('D MMM YY') ?? 'any')
                        .' – '.($record->stay_until?->isoFormat('D MMM YY') ?? 'any')
                    )),
                Tables\Columns\TextColumn::make('uses')
                    ->label('Used')
                    ->formatStateUsing(fn (Promotion $record): string => $record->max_uses === null
                        ? (string) $record->uses
                        : $record->uses.' / '.$record->max_uses),
                Tables\Columns\IconColumn::make('is_active')->label('Running')->boolean(),
            ])
            ->emptyStateHeading('No offers yet')
            ->emptyStateDescription('An offer comes off a stay after it has been priced — it never changes what a night costs.')
            ->actions([Tables\Actions\EditAction::make()])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromotions::route('/'),
            'create' => Pages\CreatePromotion::route('/create'),
            'edit' => Pages\EditPromotion::route('/{record}/edit'),
        ];
    }
}
