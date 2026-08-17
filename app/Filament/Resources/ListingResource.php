<?php

namespace App\Filament\Resources;

use App\Connectors\ConnectorFactory;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Enums\AmenityScope;
use App\Enums\ConnectorType;
use App\Enums\ListingType;
use App\Enums\PriceUnit;
use App\Enums\VehicleCategory;
use App\Enums\VehicleClass;
use App\Filament\Pages\ListingImport;
use App\Filament\Resources\ListingResource\Pages;
use App\Filament\Support\BookableUnitSchema;
use App\Filament\Support\BookingConnectorSchema;
use App\Filament\Support\CreateWebsiteAction;
use App\Filament\Support\MenuItemSchema;
use App\Filament\Support\MessagesColumn;
use App\Filament\Support\PipelineImageResolver;
use App\Filament\Support\RestaurantChannelSchema;
use App\Filament\Support\WebsiteTab;
use App\Filament\Support\WorkbookDownload;
use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\City;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use App\Services\Enrichment\ClaimInviteService;
use App\Services\ImportExport\ListingExporter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Component;

class ListingResource extends Resource
{
    use Translatable;

    protected static ?string $model = Listing::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Listing')
                    ->columnSpanFull()
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Basic information')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                // Classification — type and partner are the two fields that determine
                                // everything else; they go first so vehicle-specific fields appear
                                // immediately after the type is set.
                                Forms\Components\Section::make('Classification')
                                    ->icon('heroicon-o-tag')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Select::make('type')
                                            ->options(ListingType::class)
                                            ->live()
                                            ->required(),
                                        Forms\Components\Select::make('partner_id')
                                            ->label('Partner')
                                            ->relationship('partner', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->createOptionForm([
                                                Forms\Components\TextInput::make('name')->required(),
                                                Forms\Components\TextInput::make('email')->email(),
                                                Forms\Components\TextInput::make('phone'),
                                            ])
                                            ->createOptionAction(fn (Forms\Components\Actions\Action $action) => $action
                                                ->label('Create new partner')
                                                ->mountUsing(fn (Form $form, Component $livewire) => $form->fill([
                                                    // @phpstan-ignore property.notFound
                                                    'name' => data_get($livewire->data, 'name') ?? '',
                                                ]))
                                            ),
                                        Forms\Components\Select::make('vehicle_category')
                                            ->label('Vehicle category')
                                            ->options(VehicleCategory::class)
                                            ->helperText('Self-drive rental vs. a guided tour with a driver-guide included.')
                                            ->visible(fn (Forms\Get $get): bool => $get('type') === ListingType::Vehicle->value)
                                            ->required(fn (Forms\Get $get): bool => $get('type') === ListingType::Vehicle->value),
                                        Forms\Components\Select::make('vehicle_class')
                                            ->label('Vehicle class')
                                            ->options(VehicleClass::class)
                                            ->helperText('What the traveler actually drives. Optional — left empty, this vehicle is matched by the old "Camper" highlights heuristic instead, which cannot tell a rooftop-tent 4x4 from a motorhome.')
                                            ->visible(fn (Forms\Get $get): bool => $get('type') === ListingType::Vehicle->value),
                                        Forms\Components\TimePicker::make('pickup_time')
                                            ->label('Default pickup time')
                                            ->helperText('The rental company\'s standard opening time. Pre-fills the booking form.')
                                            ->seconds(false)
                                            ->visible(fn (Forms\Get $get): bool => $get('type') === ListingType::Vehicle->value),
                                        Forms\Components\TimePicker::make('return_time')
                                            ->label('Default return time')
                                            ->helperText('The rental company\'s standard closing time. Pre-fills the booking form.')
                                            ->seconds(false)
                                            ->visible(fn (Forms\Get $get): bool => $get('type') === ListingType::Vehicle->value),
                                    ]),

                                // Content — primary editorial fields; no section wrapper so they feel
                                // like the natural "body" of the form rather than a box inside a box.
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    // Only needed live on 'create' — the auto-slug callback below
                                    // is a no-op on 'edit'. Forcing wire:model.blur unconditionally
                                    // fired a separate sync request on every blur, which could race
                                    // the header Save button's own submit request and silently save
                                    // a stale value (confirmed via a captured request payload where
                                    // updates.data.name lagged behind the field actually on screen).
                                    // Deferred (plain wire:model, no modifier) on 'edit' bundles the
                                    // latest value into the save request itself — no separate request,
                                    // no race.
                                    ->live(onBlur: true, condition: fn (string $operation): bool => $operation === 'create')
                                    ->afterStateUpdated(fn (string $operation, ?string $state, Forms\Set $set) => $operation === 'create' ? $set('slug', Str::slug($state)) : null)
                                    ->columnSpanFull(),
                                Forms\Components\RichEditor::make('description')
                                    ->toolbarButtons([
                                        'bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo',
                                    ])
                                    // Sanitizing happens once, in Listing::setDescriptionAttribute() —
                                    // every save of a translatable field goes through
                                    // setTranslation(), which calls that mutator. Sanitizing again
                                    // here would double-process the value (e.g. double-escape
                                    // "&lt;" into "&amp;lt;" for plain-text-shaped input).
                                    ->columnSpanFull(),
                                Forms\Components\TagsInput::make('highlights')
                                    ->placeholder('Add a highlight and press enter')
                                    ->helperText('Short USPs shown on the detail page, e.g. "Free WiFi", "Waterhole views"')
                                    ->columnSpanFull(),

                                Forms\Components\Section::make('Contact')
                                    ->icon('heroicon-o-phone')
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
                                        Forms\Components\Textarea::make('address')
                                            ->maxLength(500)
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Forms\Components\KeyValue::make('social_links')
                                            ->label('Social & further links')
                                            ->helperText('Shown in the listing sidebar. Key = platform (facebook, instagram, youtube, tiktok, twitter, linkedin, pinterest, vimeo, tripadvisor), value = full URL.')
                                            ->keyLabel('Platform')
                                            ->valueLabel('URL')
                                            ->reorderable(false)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),

                                Forms\Components\Section::make('Location')
                                    ->icon('heroicon-o-map-pin')
                                    ->schema([
                                        Forms\Components\Select::make('city_id')
                                            ->label('City')
                                            ->relationship('city', 'name')
                                            ->searchable()
                                            ->preload()
                                            // Select's relationship() truncates preloaded options to 50 by
                                            // default — with 100+ Namibian settlements seeded, that silently
                                            // dropped later-alphabet cities (Windhoek, Walvis Bay...) from the
                                            // list, so a listing's already-assigned city didn't appear as
                                            // selected. Comfortably above the current ~105 rows.
                                            ->optionsLimit(300)
                                            ->columnSpanFull(),
                                        Forms\Components\TextInput::make('latitude')
                                            ->numeric(),
                                        Forms\Components\TextInput::make('longitude')
                                            ->numeric(),
                                        Forms\Components\Placeholder::make('geocode_hint')
                                            ->label('')
                                            ->content('Leave latitude/longitude blank to have them calculated automatically from the name and address on save.')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),

                                // Renamed from 'What this property has' — shorter, scans faster in the sidebar
                                Forms\Components\Section::make('Amenities')
                                    ->icon('heroicon-o-check-circle')
                                    ->description('From the shared catalogue. The moment anything is ticked here, the scraped free-text facilities stop being shown — see Listing::amenityList().')
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
                                            ->columnSpanFull(),
                                    ])
                                    ->columnSpanFull(),

                                Forms\Components\Section::make('Pricing & ratings')
                                    ->icon('heroicon-o-currency-dollar')
                                    ->schema([
                                        Forms\Components\TextInput::make('price_from')
                                            ->numeric(),
                                        Forms\Components\TextInput::make('price_currency')
                                            ->required()
                                            ->maxLength(3)
                                            ->default('NAD'),
                                        Forms\Components\Select::make('price_unit')
                                            ->label('Price is per')
                                            // Narrowed to the type's plausible units — the form state holds
                                            // either the enum (from the record) or its raw value (after the
                                            // type select is changed), so both are accepted here.
                                            ->options(function (Forms\Get $get): array {
                                                $type = $get('type');

                                                return PriceUnit::optionsForType(match (true) {
                                                    $type instanceof ListingType => $type,
                                                    is_string($type) => ListingType::tryFrom($type),
                                                    default => null,
                                                });
                                            })
                                            ->helperText('What the "price from" is quoted per. Leave empty if you do not know — the trip plan then shows the price without claiming a period, which is better than a wrong one. Set it and the plan multiplies a per-person rate by the party size.'),
                                        Forms\Components\TextInput::make('duration_minutes')
                                            ->label('Typical duration (minutes)')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(2880)
                                            ->helperText('How long the experience takes, e.g. 120 for a 2-hour quad ride. Shown next to the entry in the traveler\'s trip plan; mainly relevant for activities and guided tours.'),
                                        Forms\Components\TextInput::make('rating')
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(5)
                                            ->step(0.1)
                                            ->helperText('Manually entered from Google/TripAdvisor etc. Once this listing has at least one approved on-site review, it is recalculated automatically from those reviews and this value is overwritten.'),
                                        Forms\Components\TextInput::make('rating_count')
                                            ->label('Number of ratings')
                                            ->numeric()
                                            ->minValue(0)
                                            ->helperText('Same as Rating: manual until on-site reviews take over.'),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),

                                // Admin — slug and integrations are set once on create (slug auto-fills)
                                // and rarely revisited; collapsing keeps the form readable day-to-day.
                                Forms\Components\Section::make('Admin')
                                    ->icon('heroicon-o-cog-6-tooth')
                                    ->description('Slug auto-fills from the name on create. Change with care — the URL breaks unless a redirect is set up.')
                                    ->collapsible()
                                    ->collapsed()
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('slug')
                                            ->required()
                                            ->maxLength(255)
                                            ->unique(ignoreRecord: true)
                                            ->columnSpanFull(),
                                        Forms\Components\TextInput::make('wetu_id')
                                            ->label('Wetu property ID')
                                            ->placeholder('e.g. WETU-001')
                                            ->helperText('Set this to enable automatic content sync from Wetu'),
                                    ]),
                            ])
                            ->columns(2),

                        Forms\Components\Tabs\Tab::make('Media')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                // Must be the r2 disk, not public — AI/website/Google-Places-scraped
                                // images are stored on R2 (see GooglePlacesPhotoFinder,
                                // WebsiteContentExtractor::downloadPhoto). getUploadedFileUsing() is
                                // also required on top of that: those images are stored as full R2
                                // URLs, not disk-relative paths, and FileUpload's default resolver
                                // has no handling for that — see PipelineImageResolver.
                                // fetchFileInformation(false) is required too: FileUpload's own state
                                // hydration runs getDisk()->exists($file) on the raw stored value
                                // BEFORE getUploadedFileUsing() ever runs, so URLs/foreign-disk paths
                                // get silently dropped from the field's state no matter what the
                                // resolver does — disabling it is the only way to let the resolver
                                // see them.
                                // FilePond's own in-panel preview rasterizes to a small internal
                                // canvas and CSS-stretches it to fill the panel — that's intrinsic
                                // to its crop/zoom-capable renderer, not something any Filament
                                // FileUpload option controls (imagePreviewHeight is discarded
                                // whenever panelAspectRatio is set on a non-multiple field). The
                                // stored file itself is full resolution — these Placeholders render
                                // it directly so the tab shows the real quality.
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

                        // Before "Booking system / API" on purpose: the rooms are
                        // what the connector then sells, so the tab strip reads
                        // in the order the work is done.
                        //
                        // A relationship Repeater rather than the relation
                        // manager the partner panel uses, because a relation
                        // manager cannot be moved into the form: Filament's
                        // table action modals are <form> elements, and nesting
                        // one inside the record's own edit <form> silently
                        // breaks submission in the browser (the same trap
                        // recorded on HasPartnerMessagesTable). Both surfaces
                        // share BookableUnitSchema, so only the frame differs.
                        Forms\Components\Tabs\Tab::make('Room types')
                            ->icon('heroicon-o-home-modern')
                            // Saved truth, so it lags rows added but not yet
                            // saved. Worth it: with nothing below the form any
                            // more, this is the only way to see whether a
                            // property has its inventory entered without
                            // opening the tab — which, today, almost none do.
                            ->badge(function (?Listing $record): ?string {
                                $count = $record?->bookableUnits()->count() ?? 0;

                                return $count > 0 ? (string) $count : null;
                            })
                            // Creating the listing has to come first: the rows
                            // hang off its id, and nobody types a rate before
                            // the property exists.
                            ->visibleOn('edit')
                            ->schema([
                                Forms\Components\Repeater::make('bookableUnits')
                                    ->relationship()
                                    ->hiddenLabel()
                                    ->addActionLabel('Add a room type')
                                    ->itemLabel(fn (array $state): ?string => filled($state['name'] ?? null)
                                        ? trim($state['name'].(filled($state['code'] ?? null) ? " · {$state['code']}" : ''))
                                        : null)
                                    ->collapsible()
                                    ->collapsed()
                                    // No drag handle: there is no sort column
                                    // to write an order into, so a handle would
                                    // promise something the next page load
                                    // forgets. The traveler-facing order is by
                                    // rate (see RoomAvailability).
                                    ->reorderable(false)
                                    ->defaultItems(0)
                                    // Removing a row deletes the room type when
                                    // the listing is saved, and the foreign keys
                                    // take its calendar and its departures with
                                    // it. Cheap to confirm, expensive to undo.
                                    ->deleteAction(fn (Forms\Components\Actions\Action $action): Forms\Components\Actions\Action => $action
                                        ->requiresConfirmation()
                                        ->modalHeading('Delete this room type?')
                                        ->modalDescription('Its calendar, rates and departures go with it once the listing is saved. Switching "Is active" off instead keeps the history and takes it off sale.'))
                                    ->schema(BookableUnitSchema::schema())
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ]),

                        // Same frame as "Room types" above and for the same
                        // reason, and shown to a restaurant only: everything
                        // else has nothing to put in it, and a tab that is
                        // always empty is one every lodge has to learn to skip.
                        Forms\Components\Tabs\Tab::make('Menu')
                            ->icon('heroicon-o-list-bullet')
                            // One closure, not `->visible(…)->visibleOn('edit')`:
                            // visibleOn() is implemented *as* visible(), so the
                            // second call would silently replace the first and
                            // show this tab on every lodge in the panel.
                            ->visible(fn (Forms\Get $get, string $operation): bool => $operation === 'edit'
                                && $get('type') === ListingType::Restaurant->value)
                            ->badge(function (?Listing $record): ?string {
                                $count = $record?->menuItems()->count() ?? 0;

                                return $count > 0 ? (string) $count : null;
                            })
                            ->schema([
                                Forms\Components\Repeater::make('menuItems')
                                    ->relationship()
                                    ->hiddenLabel()
                                    ->addActionLabel('Add a menu item')
                                    ->itemLabel(fn (array $state): ?string => filled($state['name'] ?? null)
                                        ? trim($state['name'].(filled($state['category'] ?? null) ? " · {$state['category']}" : ''))
                                        : null)
                                    ->collapsible()
                                    ->collapsed()
                                    // A menu has an order and this one is
                                    // written into `sort`, so the handle keeps
                                    // its promise across a page load.
                                    ->reorderable()
                                    ->orderColumn('sort')
                                    ->defaultItems(0)
                                    ->deleteAction(fn (Forms\Components\Actions\Action $action): Forms\Components\Actions\Action => $action
                                        ->requiresConfirmation()
                                        ->modalHeading('Delete this menu item?')
                                        ->modalDescription('Orders that already contain it keep their own record of the name and the price. Switching "On the menu" off instead takes it off sale and keeps it here.'))
                                    ->schema(MenuItemSchema::schema())
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ]),

                        WebsiteTab::make(),

                        Forms\Components\Tabs\Tab::make('Booking system / API')
                            ->icon('heroicon-o-link')
                            ->schema(BookingConnectorSchema::schema()),

                        Forms\Components\Tabs\Tab::make('Visibility')
                            ->icon('heroicon-o-eye')
                            ->schema([
                                Forms\Components\Toggle::make('is_featured')
                                    ->required(),
                                Forms\Components\Toggle::make('is_homepage_pick')
                                    ->label('Homepage featured pick')
                                    ->helperText('Eligible to appear as the magazine-style cover story at the top of the homepage explore section')
                                    ->required(),
                                Forms\Components\Toggle::make('is_published')
                                    ->required(),
                                Forms\Components\Toggle::make('accepts_inquiries')
                                    ->label('Accepts inquiries')
                                    ->helperText('Shows the contact/inquiry form on the detail page')
                                    ->default(true)
                                    ->required(),
                                // Restaurants only, because they are the only
                                // listings asked for two different things. Both
                                // sit under `accepts_inquiries`: switched off
                                // above, these do nothing.
                                ...RestaurantChannelSchema::schema(),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'partnerMessages as unread_messages_count' => fn (Builder $messagesQuery): Builder => $messagesQuery
                    ->where('direction', PartnerMessage::DIRECTION_INBOUND)
                    ->whereNull('read_at'),
            ]))
            ->columns([
                MessagesColumn::make(
                    url: fn (Listing $record): string => static::getUrl('messages', ['record' => $record]),
                    // Same fallback the messages page writes to: the owner's
                    // address often sits on the listing rather than on the
                    // Partner the importer created (see ListingMessages).
                    contactEmail: fn (Listing $record): ?string => $record->partner?->email ?: $record->contact_email,
                ),
                Tables\Columns\ImageColumn::make('image')
                    // Not disk('public'): a manually-uploaded image can be on either 'r2'
                    // (the form field's disk) or 'public' (rows from before the r2 switch,
                    // or a stale legacy value) — resolveMediaUrl already knows how to check
                    // both, and also transparently handles the full-URL values the AI
                    // enrichment pipeline writes directly.
                    ->getStateUsing(fn (Listing $record): ?string => $record->image ? Controller::resolveMediaUrl($record->image) : null)
                    ->square()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $direction === 'desc'
                        ? $query->orderByRaw("(image IS NOT NULL AND image != '') desc")
                        : $query->orderByRaw("(image IS NOT NULL AND image != '') asc")),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vehicle_category')
                    ->label('Vehicle category')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('vehicle_class')
                    ->label('Vehicle class')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('partner.name')
                    ->label('Partner')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('city.name')
                    ->label('City')
                    ->searchable(),
                Tables\Columns\TextColumn::make('latitude')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('longitude')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('price_from')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_currency')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // Hidden by default like the currency, but sortable so the
                // content work of filling this in has a "which rows are still
                // blank" view to work from.
                Tables\Columns\TextColumn::make('price_unit')
                    ->label('Price is per')
                    ->placeholder('not recorded')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('rating')
                    ->numeric(1)
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_featured')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_homepage_pick')
                    ->label('Homepage pick')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_published')
                    ->boolean(),
                Tables\Columns\TextColumn::make('claim_status')
                    ->label('Claim')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'claimed' => 'success',
                        'rejected' => 'danger',
                        'unclaimed' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('scrape_source')
                    ->label('Source')
                    ->toggleable(isToggledHiddenByDefault: true),
                // Provenance of what the listing actually shows — a red badge here
                // means content we are not free to publish (see ContentSource).
                Tables\Columns\TextColumn::make('description_source')
                    ->label('Text from')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('photos_source')
                    ->label('Photos from')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(ListingType::class),
                Tables\Filters\SelectFilter::make('vehicle_category')
                    ->label('Vehicle category')
                    ->options(VehicleCategory::class),
                Tables\Filters\SelectFilter::make('vehicle_class')
                    ->label('Vehicle class')
                    ->options(VehicleClass::class),
                Tables\Filters\SelectFilter::make('claim_status')
                    ->options([
                        'unclaimed' => 'Unclaimed',
                        'claimed' => 'Claimed',
                        'rejected' => 'Rejected',
                    ]),
                Tables\Filters\TernaryFilter::make('is_published'),
                Tables\Filters\TernaryFilter::make('is_homepage_pick')
                    ->label('Homepage pick'),
                Tables\Filters\TernaryFilter::make('has_image')
                    ->label('Has image')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('image')->where('image', '!=', ''),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q->whereNull('image')->orWhere('image', '')),
                    ),
                // Imported listings arrive with no city_id on purpose — the
                // scrape's free-text region is no reliable match for a City
                // (see ImportProviders). BackfillListingCities can only fill
                // that in from an address, and the NTB source carries none, so
                // for those rows a human assigning the city is the only route.
                // Combine with the type filter for "vehicles without a city".
                Tables\Filters\TernaryFilter::make('has_city')
                    ->label('Has city')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('city_id'),
                        false: fn (Builder $query) => $query->whereNull('city_id'),
                    ),
                Tables\Filters\TernaryFilter::make('has_unread_messages')
                    ->label('Unread messages')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('partnerMessages', fn (Builder $q) => $q
                            ->where('direction', PartnerMessage::DIRECTION_INBOUND)
                            ->whereNull('read_at')),
                        false: fn (Builder $query) => $query->whereDoesntHave('partnerMessages', fn (Builder $q) => $q
                            ->where('direction', PartnerMessage::DIRECTION_INBOUND)
                            ->whereNull('read_at')),
                    ),
            ])
            ->headerActions([
                Tables\Actions\Action::make('import_listings')
                    ->label('Import listings')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->url(ListingImport::getUrl()),
                // Exports whatever the table currently shows, filters included — the
                // workbook carries the id column, so editing it and importing it back
                // through that button updates exactly these rows.
                Tables\Actions\Action::make('export_excel')
                    ->label('Export to Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function (Pages\ListListings $livewire): void {
                        $name = 'listings-'.now()->format('Y-m-d-Hi').'.xlsx';
                        $path = WorkbookDownload::path($name);

                        app(ListingExporter::class)->export($livewire->getFilteredTableQuery(), $path);

                        $livewire->redirect(WorkbookDownload::link($path, $name));
                    }),
                Tables\Actions\Action::make('export_template')
                    ->label('Empty template')
                    ->icon('heroicon-o-document-plus')
                    ->color('gray')
                    ->action(function (Pages\ListListings $livewire): void {
                        $path = WorkbookDownload::path('listings-template.xlsx');

                        app(ListingExporter::class)->template($path);

                        $livewire->redirect(WorkbookDownload::link($path, 'listings-template.xlsx'));
                    }),
            ])
            // Grouped, and the grouping is the point. Four of the actions below
            // appear only for some listings — an owner link needs a partner, the
            // Wetu import needs a Wetu connector — so rendering them inline gave
            // each row a different number of icons and moved every column after
            // them. A table whose columns do not line up costs a moment on every
            // single row. The group is one cell wide whatever is inside it.
            //
            // Edit and the website buttons stay outside it: those are the ones
            // used often enough that a second click would be felt, and all three
            // are now present on every row.
            ->actions([
                Tables\Actions\EditAction::make(),
                CreateWebsiteAction::make(),
                CreateWebsiteAction::visit(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('view_frontend')
                        ->label('')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->color('gray')
                        ->tooltip(fn (Listing $record): string => $record->is_published
                            ? 'View on namibway.com'
                            : 'Preview draft on namibway.com (not published yet — visible to admins only)')
                        ->url(fn (Listing $record): string => route('listings.show', $record->slug))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('copy_owner_link')
                        ->label('')
                        ->icon('heroicon-o-clipboard-document')
                        ->color('gray')
                        ->tooltip('Copy the owner preview/edit/publish link (same one the claim-invite email sends)')
                        ->visible(fn (Listing $record): bool => $record->partner !== null)
                        ->modalHeading('Owner Link')
                        ->form([
                            Forms\Components\TextInput::make('link')
                                ->label('Owner preview/edit/publish link')
                                ->readOnly()
                                ->default(function (Listing $record, ClaimInviteService $inviter): string {
                                    $partner = $record->partner;

                                    if (! $partner instanceof Partner) {
                                        return '';
                                    }

                                    if (blank($partner->claim_token)) {
                                        $partner->update(['claim_token' => Str::random(48)]);
                                    }

                                    return $inviter->listingUrl($record, $partner);
                                })
                                ->helperText('Works without an account — opens the same draft preview, edit page, and publish flow the owner gets.'),
                        ])
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),
                    Tables\Actions\Action::make('import_wetu')
                        ->label('Import from Wetu')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('info')
                        ->visible(fn (Listing $record): bool => $record->partner?->connector_type === ConnectorType::Wetu)
                        ->requiresConfirmation()
                        ->modalHeading('Import content from Wetu')
                        ->modalDescription('This will overwrite the name, description, highlights, region, and coordinates with data from Wetu. Existing images are not replaced.')
                        ->action(function (Listing $record): void {
                            $partner = $record->partner;

                            if (! $partner instanceof Partner) {
                                Notification::make()->title('No partner linked to this listing.')->danger()->send();

                                return;
                            }

                            try {
                                $wetuId = $record->wetu_id;

                                if (blank($wetuId)) {
                                    Notification::make()->title('This listing has no Wetu property ID configured.')->danger()->send();

                                    return;
                                }

                                $content = ConnectorFactory::makeContent($partner)->fetchPropertyContent($wetuId);

                                // $content->region (a free-text string from Wetu) has no reliable
                                // match against a City — city_id is left for an admin to assign
                                // manually, same as any other Wetu-imported listing.
                                $record->update(array_filter([
                                    'name' => $content->name ?: null,
                                    'description' => $content->description,
                                    'highlights' => $content->highlights ?: null,
                                    'latitude' => $content->latitude,
                                    'longitude' => $content->longitude,
                                ], fn ($v) => $v !== null));

                                Notification::make()
                                    ->title('Content imported from Wetu')
                                    ->body("Imported: {$content->name}")
                                    ->success()
                                    ->send();

                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('Wetu import failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    Tables\Actions\Action::make('test_connector')
                        ->label('Test connector')
                        ->icon('heroicon-o-signal')
                        ->color('info')
                        ->visible(fn (Listing $record): bool => in_array(
                            $record->partner?->connector_type,
                            [ConnectorType::ResConnect, ConnectorType::NightsBridge, ConnectorType::HopeCloud],
                            true
                        ) && filled($record->connector_property_code))
                        ->action(function (Listing $record): void {
                            $partner = $record->partner;

                            if (! $partner instanceof Partner) {
                                Notification::make()->title('No partner linked to this listing.')->danger()->send();

                                return;
                            }

                            try {
                                $connector = ConnectorFactory::makeBooking($partner);

                                $response = $connector->checkAvailability(new AvailabilityRequest(
                                    propertyCode: $record->connector_property_code ?? '',
                                    checkIn: now()->addDays(30),
                                    checkOut: now()->addDays(31),
                                    adults: 2,
                                ));

                                if ($response->available) {
                                    Notification::make()
                                        ->title('Connector reachable')
                                        ->body(count($response->roomTypes).' room type(s) returned for a test date 30 days out — this listing is correctly connected.')
                                        ->success()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title('Connected, but no test availability')
                                        ->body(($response->error ?? 'No rooms for the test dates.').' The connection itself worked — this can be normal.')
                                        ->warning()
                                        ->send();
                                }
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('Connector test failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ])
                    ->label('More')
                    ->icon('heroicon-o-ellipsis-horizontal')
                    ->color('gray'),
            ], position: ActionsPosition::BeforeColumns)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('publish')
                        ->label('Publish')
                        ->icon('heroicon-o-eye')
                        ->color('success')
                        ->action(fn ($records) => $records->each->update(['is_published' => true]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('unpublish')
                        ->label('Unpublish')
                        ->icon('heroicon-o-eye-slash')
                        ->color('gray')
                        ->action(fn ($records) => $records->each->update(['is_published' => false]))
                        ->deselectRecordsAfterCompletion(),
                    // The other half of the "Has city: no" filter — finding
                    // 250-odd city-less imports is only useful if fixing them
                    // doesn't mean opening 250 edit forms.
                    //
                    // Fill-only, deliberately: a listing that already has a
                    // city keeps it and is reported as skipped. Bulk-writing
                    // over existing city_id values is exactly what
                    // namibway:backfill-listing-cities once did, and the
                    // damage needed a restore from backup (see CLAUDE.md's
                    // data-loss lesson). Correcting a wrong city stays a
                    // per-record edit, where the current value is visible.
                    Tables\Actions\BulkAction::make('assign_city')
                        ->label('Assign city')
                        ->icon('heroicon-o-map-pin')
                        ->color('gray')
                        ->form([
                            Forms\Components\Select::make('city_id')
                                ->label('City')
                                ->options(fn () => City::query()->orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->helperText('Only applied to selected listings that have no city yet.'),
                        ])
                        ->action(fn (Collection $records, array $data) => self::assignCity($records, (int) $data['city_id']))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Named method rather than an inline closure so $records carries an
     * explicit generic type — PHPStan can't infer Collection<int, Listing>
     * from an untyped closure parameter (same reason as
     * EnrichmentResource::queueWebsiteScrape).
     *
     * @param  Collection<int, Listing>  $records
     */
    private static function assignCity(Collection $records, int $cityId): void
    {
        $fillable = $records->filter(fn (Listing $listing) => $listing->city_id === null);
        $skipped = $records->count() - $fillable->count();

        $fillable->each(fn (Listing $listing) => $listing->update(['city_id' => $cityId]));

        Notification::make()
            ->title("Assigned a city to {$fillable->count()} listing(s)")
            ->body($skipped > 0 ? "{$skipped} already had one and were left untouched." : null)
            ->success()
            ->send();
    }

    /**
     * Deliberately empty. Room types used to be a relation manager here, which
     * Filament can only render as a box underneath the form — below the tab
     * strip, so the one part of a listing that is pure data entry sat outside
     * the place every other part of it is edited. They are the "Room types" tab
     * now (see form()). The relation manager itself still exists and is still
     * the partner panel's surface; re-registering it here would show the same
     * rows twice on one page.
     */
    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListListings::route('/'),
            'create' => Pages\CreateListing::route('/create'),
            'edit' => Pages\EditListing::route('/{record}/edit'),
            'messages' => Pages\ListingMessages::route('/{record}/messages'),
        ];
    }
}
