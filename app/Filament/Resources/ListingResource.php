<?php

namespace App\Filament\Resources;

use App\Connectors\ConnectorFactory;
use App\Connectors\ResConnect\DTOs\AvailabilityRequest;
use App\Enums\ConnectorType;
use App\Enums\ListingType;
use App\Filament\Resources\ListingResource\Pages;
use App\Filament\Support\BookingConnectorSchema;
use App\Filament\Support\PipelineImageResolver;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use App\Services\Enrichment\ClaimInviteService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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
                                Forms\Components\Select::make('type')
                                    ->options(ListingType::class)
                                    ->required(),
                                Forms\Components\Select::make('partner_id')
                                    ->label('Partner')
                                    ->relationship('partner', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('name')->required(),
                                        Forms\Components\TextInput::make('email')->email(),
                                    ]),
                                Forms\Components\TextInput::make('wetu_id')
                                    ->label('Wetu property ID')
                                    ->placeholder('e.g. WETU-001')
                                    ->helperText('Set this to enable automatic content sync from Wetu'),
                                Forms\Components\TextInput::make('slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true)
                                    ->helperText('Auto-filled from the name when creating. Change with care afterwards — the URL breaks unless a redirect is set up.'),
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
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),

                                Forms\Components\Section::make('Location')
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

                                Forms\Components\Section::make('Pricing & ratings')
                                    ->schema([
                                        Forms\Components\TextInput::make('price_from')
                                            ->numeric(),
                                        Forms\Components\TextInput::make('price_currency')
                                            ->required()
                                            ->maxLength(3)
                                            ->default('NAD'),
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
                Tables\Columns\TextColumn::make('unread_messages_count')
                    ->label('Messages')
                    ->badge(fn (Listing $record): bool => $record->partner?->email !== null)
                    ->icon(fn (Listing $record): ?string => $record->partner?->email !== null
                        ? 'heroicon-o-envelope'
                        : null)
                    ->formatStateUsing(fn (int $state, Listing $record): string => match (true) {
                        $record->partner?->email === null => '',
                        $state > 0 => (string) $state,
                        default => "\u{00A0}",
                    })
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->tooltip(fn (int $state, Listing $record): ?string => match (true) {
                        $record->partner?->email === null => null,
                        $state > 0 => "{$state} unread message(s) — click to view",
                        default => 'View messages',
                    })
                    ->url(fn (Listing $record): ?string => $record->partner?->email !== null
                        ? static::getUrl('messages', ['record' => $record])
                        : null)
                    ->sortable(),
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
            ->actions([
                Tables\Actions\EditAction::make(),
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
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

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
