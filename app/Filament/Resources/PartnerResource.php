<?php

namespace App\Filament\Resources;

use App\Enums\ConnectorType;
use App\Enums\OperatingMode;
use App\Enums\SettlementModel;
use App\Filament\Resources\PartnerResource\Pages;
use App\Filament\Support\MessagesColumn;
use App\Filament\Support\WebsiteTab;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerMessage;
use App\Models\PaymentSettings;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartnerResource extends Resource
{
    use Translatable;

    protected static ?string $model = Partner::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Content';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Partner')
                    ->columnSpanFull()
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Basic information')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                // Identity — the most-edited fields, no section wrapper needed
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('short_description')
                                    ->label('Short description')
                                    ->maxLength(240)
                                    ->helperText('One or two sentences — used as the hero subline on the website. Keep it short.')
                                    ->columnSpanFull(),
                                Forms\Components\Textarea::make('bio')
                                    ->label('Full description')
                                    ->rows(5)
                                    ->columnSpanFull(),

                                // Contact — grouped so the three fields read as one answer to "how do I reach them?"
                                Forms\Components\Section::make('Contact')
                                    ->icon('heroicon-o-phone')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('email')
                                            ->email()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('phone')
                                            ->tel()
                                            ->maxLength(50),
                                        Forms\Components\TextInput::make('website')
                                            ->url()
                                            ->maxLength(255)
                                            ->columnSpanFull(),
                                    ]),

                                // Social — secondary, collapsible so they don't clutter the form when empty
                                Forms\Components\Section::make('Social media')
                                    ->icon('heroicon-o-share')
                                    ->columnSpanFull()
                                    ->collapsible()
                                    ->schema([
                                        Forms\Components\KeyValue::make('social_links')
                                            ->hiddenLabel()
                                            ->helperText('Key = platform (instagram, facebook, linkedin, youtube, tiktok, twitter, tripadvisor), value = full URL.')
                                            ->keyLabel('Platform')
                                            ->valueLabel('URL')
                                            ->reorderable(false)
                                            ->columnSpanFull(),
                                    ]),

                                Forms\Components\Section::make('Location')
                                    ->icon('heroicon-o-map-pin')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('address')
                                            ->maxLength(255)
                                            ->columnSpanFull(),
                                        Forms\Components\TextInput::make('latitude')
                                            ->numeric()
                                            ->step(0.0000001),
                                        Forms\Components\TextInput::make('longitude')
                                            ->numeric()
                                            ->step(0.0000001),
                                    ]),

                            ])
                            ->columns(2),

                        Forms\Components\Tabs\Tab::make('Media')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Forms\Components\FileUpload::make('logo')
                                    ->image()
                                    ->disk('r2')
                                    ->directory('partners')
                                    ->imageEditor()
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Tabs\Tab::make('Bookings')
                            ->icon('heroicon-o-envelope')
                            ->schema([
                                Forms\Components\Placeholder::make('booking_state')
                                    ->label('What happens to booking mail today')
                                    ->content(fn (Get $get): string => match (true) {
                                        (bool) $get('booking_demo_mode') => 'Test mode — every confirmation goes to the demo address below and never to a guest.',
                                        (bool) $get('booking_enabled') => 'Live — guests receive their confirmations and this partner receives the booking notices.',
                                        default => 'Not live — every confirmation goes to the NamibWay team mailbox, tagged with the property name.',
                                    }),

                                Forms\Components\Select::make('operating_mode')
                                    ->label('What they bought')
                                    ->options(collect(OperatingMode::cases())
                                        ->mapWithKeys(fn (OperatingMode $mode) => [$mode->value => $mode->label()])
                                        ->all())
                                    ->default(OperatingMode::Full->value)
                                    ->required()
                                    ->live()
                                    ->columnSpanFull()
                                    // Ours to set, like the live switch above:
                                    // it is what the partner is paying for.
                                    ->helperText(fn (Get $get): string => (OperatingMode::tryFrom((string) $get('operating_mode')) ?? OperatingMode::Full)->description()
                                        .' Changing this hides or shows screens and changes nothing that is stored — a partner can be upgraded at any time.'),

                                Forms\Components\Toggle::make('booking_enabled')
                                    ->label('Bookings are live')
                                    ->helperText('Switching this on means real guests receive real mail in NamibWay’s name. It is ours to decide, not the partner’s.')
                                    ->live(),

                                Forms\Components\Toggle::make('booking_demo_mode')
                                    ->label('Test mode')
                                    ->helperText('The operator is trying the system against their own inventory. Nothing reaches a guest while this is on — it wins over the switch above.')
                                    ->live(),

                                Forms\Components\TextInput::make('booking_email')
                                    ->label('Where bookings should go')
                                    ->email()
                                    ->maxLength(255)
                                    ->helperText('The reservations desk, which is often not the address the partner was first reached on. Empty falls back to the contact email, and then to us — booking mail is never simply dropped.'),

                                Forms\Components\TextInput::make('booking_demo_email')
                                    ->label('Test address')
                                    ->email()
                                    ->maxLength(255)
                                    ->helperText('Where everything goes while test mode is on. Empty falls back to the team mailbox.')
                                    ->visible(fn (Get $get): bool => (bool) $get('booking_demo_mode')),
                            ])
                            ->columns(2),

                        Forms\Components\Tabs\Tab::make('Commission and deposit')
                            ->icon('heroicon-o-banknotes')
                            ->schema([
                                Forms\Components\Placeholder::make('rate_chain')
                                    ->label('How these resolve')
                                    ->columnSpanFull()
                                    ->content('Most specific wins: a listing’s own rate, then this partner’s, then the platform default under Settings → Commission and deposits. Leave a field empty to follow the level above.'),

                                Forms\Components\TextInput::make('commission_rate')
                                    ->label('Commission')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->suffix('%')
                                    ->placeholder(fn (): string => static::platformRateLabel('commission'))
                                    // Only ever here and on the listing. The
                                    // partner panel has no field for this at
                                    // all, by design — PAYMENTS.md § 2a.
                                    ->helperText('Ours to set. The partner can see what they pay and cannot change it.'),

                                Forms\Components\TextInput::make('deposit_rate')
                                    ->label('Deposit')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->suffix('%')
                                    ->placeholder(fn (): string => static::platformRateLabel('deposit'))
                                    ->helperText('The partner’s to choose within the range we allow — set one here only where it was negotiated.')
                                    ->live(),

                                Forms\Components\Toggle::make('allow_zero_deposit')
                                    ->label('May collect no deposit at all')
                                    ->columnSpanFull()
                                    ->live()
                                    // The consequence is stated where the
                                    // choice is made, not discovered a month
                                    // later — PAYMENTS.md § 2.
                                    ->helperText('Switching this on lets the partner set a 0% deposit, which puts them on the agency model: we collect nothing from their guests and invoice them for commission afterwards. That is the only arrangement where our money depends on somebody else paying an invoice, and the only lever we have is switching their bookings off.'),

                                Forms\Components\Placeholder::make('settlement_model')
                                    ->label('Which arrangement that is')
                                    ->columnSpanFull()
                                    ->content(fn (Get $get): string => static::settlementLabel($get('deposit_rate'))),
                            ])
                            ->columns(2),

                        Forms\Components\Tabs\Tab::make('Booking system / API')
                            ->icon('heroicon-o-link')
                            ->schema([
                                Forms\Components\Select::make('connector_type')
                                    ->label('Connector')
                                    ->options(collect(ConnectorType::cases())->mapWithKeys(
                                        fn (ConnectorType $c) => [$c->value => $c->label()]
                                    ))
                                    ->placeholder('None (manual handling)')
                                    ->helperText('Connect this partner\'s account to their property management system (API credentials). Once set here, connect each individual listing to its property code on the listing\'s own "Booking system" section.')
                                    ->live()
                                    ->native(false),

                                Forms\Components\KeyValue::make('connector_config')
                                    ->label('Connector Config')
                                    ->helperText('Key/value pairs stored encrypted. ResConnect: api_key, base_url (opt). NightsBridge: bbid, api_key. hopeCloud: api_key, account_id. Wetu: api_key.')
                                    ->keyLabel('Key')
                                    ->valueLabel('Value')
                                    ->visible(fn (Get $get) => filled($get('connector_type'))),

                                Forms\Components\Placeholder::make('connector_verified_status')
                                    ->label('Verification status')
                                    ->content(function (?Partner $record): string {
                                        if (! $record?->connector_type) {
                                            return '—';
                                        }

                                        return $record->connector_verified_at !== null
                                            ? 'Verified '.$record->connector_verified_at->diffForHumans()
                                            : 'Pending review — this may have been submitted by the partner themselves. Check the credentials work, then save this tab to mark it verified.';
                                    })
                                    ->visible(fn (Get $get) => filled($get('connector_type'))),
                            ]),

                        Forms\Components\Tabs\Tab::make('Portal Access')
                            ->icon('heroicon-o-key')
                            ->schema([
                                Forms\Components\Select::make('portal_user_id')
                                    ->label('Portal user')
                                    ->options(User::whereNull('partner_id')->orWhereHas('partner', fn ($q) => $q->whereKey(0))->pluck('email', 'id'))
                                    ->searchable()
                                    ->placeholder('No portal access')
                                    ->helperText('Assigning a user here grants them access to /partner for this property.')
                                    ->dehydrated(false)
                                    ->afterStateHydrated(function ($component, ?Partner $record) {
                                        if (! $record) {
                                            return;
                                        }

                                        $user = User::where('partner_id', $record->id)->first();
                                        $component->state($user?->id);
                                    })
                                    ->saveRelationshipsUsing(function (?int $state, Partner $record) {
                                        User::where('partner_id', $record->id)->whereKeyNot($state ?? 0)->update(['partner_id' => null]);

                                        if ($state) {
                                            User::whereKey($state)->update(['partner_id' => $record->id]);
                                        }
                                    }),
                            ]),

                        WebsiteTab::make(),
                    ]),
            ]);
    }

    /**
     * The number an empty field will fall through to, shown as its
     * placeholder.
     *
     * A blank box that silently means "5%" is a blank box somebody fills in
     * with 5 to be safe — and then the platform rate stops moving them when it
     * changes, which is the whole point of the chain.
     */
    protected static function platformRateLabel(string $which): string
    {
        $settings = PaymentSettings::current();

        $rate = $which === 'commission' ? $settings->commission_rate : $settings->deposit_rate;

        return rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.').'% (platform default)';
    }

    /**
     * The settlement model this deposit means, said out loud.
     *
     * Derived and never stored — see App\Enums\SettlementModel. Showing it
     * here is what makes "one number, three behaviours" legible instead of
     * something the reader has to know.
     */
    protected static function settlementLabel(mixed $depositRate): string
    {
        $rate = blank($depositRate)
            ? PaymentSettings::current()->deposit_rate
            : (float) $depositRate;

        $model = SettlementModel::forDepositRate($rate);

        return $model->label().' — '.$model->description();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'messages as unread_messages_count' => fn (Builder $messagesQuery): Builder => $messagesQuery
                    ->where('direction', PartnerMessage::DIRECTION_INBOUND)
                    ->whereNull('read_at'),
            ]))
            ->columns([
                MessagesColumn::make(
                    url: fn (Partner $record): string => static::getUrl('messages', ['record' => $record]),
                    contactEmail: fn (Partner $record): ?string => $record->email,
                ),
                Tables\Columns\ImageColumn::make('logo')
                    // Not disk('public'): logo can be on either 'r2' (current default)
                    // or 'public' (rows uploaded before the r2 switch) — resolveMediaUrl
                    // already knows how to check both.
                    ->getStateUsing(fn (Partner $record): ?string => $record->logo ? Controller::resolveMediaUrl($record->logo) : null)
                    ->circular(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone'),
                Tables\Columns\TextColumn::make('listings_count')
                    ->counts('listings')
                    ->label('Listings'),
                Tables\Columns\TextColumn::make('outreach_status')
                    ->label('Outreach')
                    ->state(function (Partner $record): string {
                        if ($record->claimed_at) {
                            return 'Claimed';
                        }
                        if ($record->claim_rejected_at) {
                            return 'Declined';
                        }
                        if ($record->claim_token_sent_at) {
                            return 'Contacted';
                        }
                        if ($record->email) {
                            return 'Ready to contact';
                        }

                        return 'No email';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Claimed' => 'success',
                        'Declined' => 'danger',
                        'Contacted' => 'info',
                        'Ready to contact' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('connector_type')
                    ->label('Connector')
                    ->formatStateUsing(fn ($state) => $state instanceof ConnectorType ? $state->label() : '—')
                    ->badge()
                    ->color(fn ($state) => $state instanceof ConnectorType && $state !== ConnectorType::Manual ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('has_unread_messages')
                    ->label('Unread messages')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('messages', fn (Builder $q) => $q
                            ->where('direction', PartnerMessage::DIRECTION_INBOUND)
                            ->whereNull('read_at')),
                        false: fn (Builder $query) => $query->whereDoesntHave('messages', fn (Builder $q) => $q
                            ->where('direction', PartnerMessage::DIRECTION_INBOUND)
                            ->whereNull('read_at')),
                    ),
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartners::route('/'),
            'create' => Pages\CreatePartner::route('/create'),
            'edit' => Pages\EditPartner::route('/{record}/edit'),
            'messages' => Pages\PartnerMessages::route('/{record}/messages'),
        ];
    }
}
