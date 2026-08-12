<?php

namespace App\Filament\Partner\Resources;

use App\Enums\AmenityScope;
use App\Enums\ListingType;
use App\Enums\PriceUnit;
use App\Enums\SettlementModel;
use App\Enums\VehicleCategory;
use App\Enums\VehicleClass;
use App\Filament\Partner\Resources\ListingResource\Pages;
use App\Filament\Resources\ListingResource\RelationManagers as AdminRelationManagers;
use App\Filament\Support\BookingConnectorSchema;
use App\Filament\Support\CreateWebsiteAction;
use App\Filament\Support\PipelineImageResolver;
use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\Listing;
use App\Services\Payments\RateResolver;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListingResource extends Resource
{
    use Translatable;

    protected static ?string $model = Listing::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'My Listings';

    protected static ?string $breadcrumb = 'Listings';

    /** @return Builder<Listing> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('partner_id', auth()->user()?->partner_id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Content')
                    ->description('This content was auto-imported from Wetu. You can edit any field to override it.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\RichEditor::make('description')
                            ->toolbarButtons([
                                'bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo',
                            ])
                            // Sanitizing happens once, in Listing::setDescriptionAttribute() — see
                            // the equivalent field in the admin ListingResource. A plain Textarea
                            // would show the stored value's HTML-escaped entities (e.g. "&#039;")
                            // as literal text, and re-saving would escape them a second time.
                            ->columnSpanFull(),
                        Forms\Components\TagsInput::make('highlights')
                            ->placeholder('Add a highlight...')
                            ->columnSpanFull(),
                        Forms\Components\Select::make('city_id')
                            ->label('City')
                            ->relationship('city', 'name')
                            ->searchable()
                            ->preload()
                            // See the equivalent field in the admin ListingResource — without this,
                            // the default 50-option preload silently drops later-alphabet cities.
                            ->optionsLimit(300),
                        Forms\Components\TextInput::make('price_from')
                            ->numeric()
                            ->prefix('NAD')
                            // The label used to read "(per night)", which was the
                            // assumption this field is meant to stop making — an
                            // activity operator entering a per-person rate here
                            // was told it meant something else entirely.
                            ->label('Price from'),
                        Forms\Components\Select::make('price_unit')
                            ->label('Price is per')
                            ->options(fn (?Listing $record): array => PriceUnit::optionsForType($record?->type))
                            ->helperText('What the price above is quoted per. Travelers see this next to the price, and their trip plan multiplies a per-person rate by the size of their party. Leave it empty if it varies — the plan then shows the price without stating a period.'),
                        Forms\Components\TextInput::make('duration_minutes')
                            ->label('Typical duration (minutes)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(2880)
                            ->helperText('How long the experience takes, e.g. 120 for a 2-hour quad ride. Shown to travelers in their trip plan.')
                            ->visible(fn (?Listing $record): bool => in_array($record?->type, [ListingType::Activity, ListingType::Vehicle], true)),
                        Forms\Components\Select::make('vehicle_category')
                            ->label('Vehicle category')
                            ->options(VehicleCategory::class)
                            ->helperText('Self-drive rental vs. a guided tour with a driver-guide included.')
                            ->visible(fn (?Listing $record): bool => $record?->type === ListingType::Vehicle)
                            ->required(fn (?Listing $record): bool => $record?->type === ListingType::Vehicle),
                        Forms\Components\Select::make('vehicle_class')
                            ->label('Vehicle class')
                            ->options(VehicleClass::class)
                            ->helperText('What the traveler actually drives. Setting this is how your vehicle reaches travelers who pick that class when planning.')
                            ->visible(fn (?Listing $record): bool => $record?->type === ListingType::Vehicle),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Contact')
                    ->schema([
                        Forms\Components\TextInput::make('contact_person')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(50),
                        Forms\Components\TextInput::make('contact_email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('website')
                            ->url()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('address')
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->helperText('Used to place this listing on the map automatically.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Photos')
                    ->schema([
                        // fetchFileInformation(false) + getUploadedFileUsing() together: see
                        // the comment on the equivalent fields in the admin ListingResource —
                        // partner-owned listings' image/gallery values can just as easily be
                        // full R2 URLs or paths relative to a different disk (set via the
                        // admin panel or the enrichment pipeline), which the default resolver
                        // silently drops before it's even reached.
                        //
                        // FilePond's own in-panel preview rasterizes to a small internal canvas
                        // and CSS-stretches it to fill the panel — intrinsic to its crop/zoom-
                        // capable renderer, not something any Filament FileUpload option
                        // controls. These Placeholders render the stored file directly so the
                        // tab shows the real quality.
                        Forms\Components\Placeholder::make('image_preview')
                            ->label('Current hero image')
                            ->content(function (?Listing $record): HtmlString {
                                if (! $record?->image) {
                                    return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">No image set yet.</span>');
                                }

                                $url = e(Controller::resolveMediaUrl($record->image));

                                return new HtmlString("<img src=\"{$url}\" style=\"max-width: 100%; max-height: 420px; border-radius: 0.5rem; object-fit: cover;\" />");
                            })
                            ->visibleOn('edit')
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('image')
                            ->label('Hero image')
                            ->image()
                            ->disk('r2')
                            ->directory('listings')
                            ->imageEditor()
                            ->openable()
                            ->panelAspectRatio('16:9')
                            ->fetchFileInformation(false)
                            ->getUploadedFileUsing(PipelineImageResolver::resolve(...))
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('gallery_preview')
                            ->label('Current gallery')
                            ->content(function (?Listing $record): HtmlString {
                                $images = $record->gallery ?? [];

                                if (empty($images)) {
                                    return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">No gallery images yet.</span>');
                                }

                                $thumbs = collect($images)
                                    ->map(fn (string $path) => '<img src="'.e(Controller::resolveMediaUrl($path)).'" style="height: 120px; border-radius: 0.5rem; object-fit: cover;" />')
                                    ->implode('');

                                return new HtmlString('<div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">'.$thumbs.'</div>');
                            })
                            ->visibleOn('edit')
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('gallery')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->panelLayout('grid')
                            ->itemPanelAspectRatio(1)
                            ->imageEditor()
                            ->openable()
                            ->disk('r2')
                            ->directory('listings/gallery')
                            ->fetchFileInformation(false)
                            ->getUploadedFileUsing(PipelineImageResolver::resolve(...))
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('What this property has')
                    ->description('From the shared list, so two lodges describing the same thing describe it the same way.')
                    ->schema([
                        Forms\Components\Select::make('amenities')
                            ->hiddenLabel()
                            ->relationship('amenities', 'name')
                            ->getOptionLabelFromRecordUsing(fn (Amenity $record): string => $record->label())
                            ->options(fn (): array => Amenity::catalogue(AmenityScope::Property)
                                ->mapWithKeys(fn (Amenity $amenity) => [$amenity->id => $amenity->label()])
                                ->all())
                            ->multiple()
                            ->preload()
                            ->searchable()
                            // Once anything is chosen here, the scraped
                            // free-text `facilities` stops being shown at all —
                            // see Listing::amenityList(). Saying so on the
                            // screen matters, because the first tick changes
                            // what a traveller reads.
                            ->helperText('The moment you tick anything here, this replaces whatever was collected about the property automatically.')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Settings')
                    ->schema([
                        Forms\Components\Toggle::make('accepts_inquiries')
                            ->label('Accept booking inquiries'),
                        Forms\Components\Placeholder::make('content_synced_at')
                            ->label('Content last synced from Wetu')
                            ->content(fn (Listing $record): string => $record->content_synced_at
                                ? $record->content_synced_at->diffForHumans()
                                : 'Never'
                            )
                            ->visibleOn('edit'),
                    ])
                    ->columns(2),

                /*
                 * The deposit is the partner's number and the commission is
                 * ours — PAYMENTS.md § 2a. So the commission appears here as a
                 * *statement*, never as a field: there is no input to post to,
                 * and `commission_rate` is not in this form's schema at all,
                 * which is what makes a crafted request as useless as the UI.
                 */
                Forms\Components\Section::make('Deposit and commission')
                    ->description('What your guests pay up front, and what NamibWay earns on a booking.')
                    ->schema([
                        Forms\Components\TextInput::make('deposit_rate')
                            ->label('Deposit')
                            ->numeric()
                            ->suffix('%')
                            ->live(onBlur: true)
                            ->placeholder(fn (?Listing $record): string => static::inheritedDepositLabel($record))
                            ->helperText(fn (?Listing $record): string => 'Leave empty to use your account’s deposit. '
                                .'The lowest you can set is '.static::formatRate(static::floorFor($record)).'%.')
                            ->rules([
                                fn (?Listing $record) => function (string $attribute, mixed $value, callable $fail) use ($record): void {
                                    if (blank($value)) {
                                        return;
                                    }

                                    $partner = $record?->partner;

                                    if ($partner === null) {
                                        return;
                                    }

                                    // Server-side, and the same call the API
                                    // would make: a min() on the input is a
                                    // hint, not a rule.
                                    $refusal = app(RateResolver::class)->refuseDeposit($partner, (float) $value);

                                    if ($refusal !== null) {
                                        $fail($refusal);
                                    }
                                },
                            ]),

                        Forms\Components\Placeholder::make('commission_rate_display')
                            ->label('NamibWay commission')
                            ->content(fn (?Listing $record): string => static::commissionLabel($record)),

                        // What this deposit *means*, stated where it is chosen
                        // rather than discovered a month later — PAYMENTS.md
                        // § 2. Live on the field above, so a property typing 0
                        // reads the consequence as they type it.
                        Forms\Components\Placeholder::make('settlement_model_display')
                            ->label('How the money will flow')
                            ->columnSpanFull()
                            ->content(fn (?Listing $record, Get $get): string => static::settlementLabel($record, $get('deposit_rate'))),
                    ])
                    ->columns(2),

                Forms\Components\Tabs::make('booking_connector_tabs')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Booking system')
                            ->icon('heroicon-o-link')
                            ->schema(BookingConnectorSchema::schema()),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /** What an empty deposit field falls through to. */
    protected static function inheritedDepositLabel(?Listing $record): string
    {
        if ($record === null) {
            return '';
        }

        return static::formatRate(app(RateResolver::class)->for($record)->depositRate).'%';
    }

    /**
     * What this property pays us, said plainly. A partner may of course see
     * it; they simply do not set it.
     */
    protected static function commissionLabel(?Listing $record): string
    {
        if ($record === null) {
            return '';
        }

        $rates = app(RateResolver::class)->for($record);

        return static::formatRate($rates->commissionRate).'% of the stay, before VAT and the tourism levy.';
    }

    /**
     * The arrangement this deposit puts the property in, in their words.
     *
     * Derived, never stored — see App\Enums\SettlementModel. The point of
     * showing it is that "0%" and "we will invoice you for commission, with
     * payment terms" are the same decision, and only one of them is obvious
     * from a number in a box.
     */
    protected static function settlementLabel(?Listing $record, mixed $typedRate): string
    {
        if ($record === null) {
            return '';
        }

        $rate = blank($typedRate)
            ? app(RateResolver::class)->for($record)->depositRate
            : (float) $typedRate;

        $model = SettlementModel::forDepositRate($rate);

        return $model->description();
    }

    protected static function floorFor(?Listing $record): float
    {
        if ($record === null) {
            return (float) config('payments.commission_rate', 5.0);
        }

        return app(RateResolver::class)->for($record)->minimumDepositRate;
    }

    /** 15 rather than 15.000. */
    protected static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->disk('public')
                    ->circular(false)
                    ->width(60)
                    ->height(40),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('vehicle_category')
                    ->label('Vehicle category')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('vehicle_class')
                    ->label('Vehicle class')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('city.name')
                    ->label('City')
                    ->sortable(),
                Tables\Columns\IconColumn::make('accepts_inquiries')
                    ->label('Accepts inquiries')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean(),
                Tables\Columns\TextColumn::make('content_synced_at')
                    ->label('Wetu sync')
                    ->since()
                    ->placeholder('Never'),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                // The same button the team has, and switched off until the
                // subscription exists to unlock it. Shown rather than hidden:
                // the owner should see that a website is one click away, which
                // is the whole offer.
                CreateWebsiteAction::locked(),
                // Shown whether or not the owner may build one: a site made for
                // them by the team is still theirs to look at.
                CreateWebsiteAction::visit(),
            ])
            ->paginated(false);
    }

    /**
     * Partners are the people who actually know their own room inventory, so
     * they get the same editor the team has. Scoping is inherited: the relation
     * manager only ever operates on the listing it was opened from, and this
     * panel already restricts which listings a partner can reach.
     */
    public static function getRelations(): array
    {
        return [
            AdminRelationManagers\BookableUnitsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListListings::route('/'),
            'edit' => Pages\EditListing::route('/{record}/edit'),
        ];
    }
}
