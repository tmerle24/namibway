<?php

namespace App\Filament\Support;

use App\Enums\ConnectorType;
use App\Models\Listing;
use App\Models\Partner;
use Filament\Forms;
use Filament\Forms\Get;

/**
 * "Booking system / API" tab content, shared by the admin and partner-portal
 * Listing forms so both a staff editor and a property owner get the same
 * guided setup. Shows a step-by-step wizard the first time a partner has no
 * connector configured yet; once one exists, just the per-listing field
 * (property code, or Wetu ID) plus a read-only status line — changing the
 * connector type itself stays a partner-level decision, not repeated here.
 *
 * The wizard's connector_setup_type/credential fields are deliberately
 * plain, un-namespaced form fields rather than a Group::relationship()
 * binding to Partner: nesting a relationship-saved encrypted-cast field
 * inside a Translatable resource (the admin Listing form) hits a real
 * Filament/spatie-translatable incompatibility — the translatable content
 * driver writes the relationship's raw state instead of routing it through
 * the model's casts, so an already-encrypted string gets written straight
 * into the jsonb column and Postgres rejects it. persistPartnerFields()
 * below, called from each resource's mutateFormDataBeforeSave/Create, does
 * the same job through a plain Partner::update() instead.
 */
class BookingConnectorSchema
{
    /** @var array<int, string> */
    private const PSEUDO_FIELDS = [
        'connector_setup_type',
        'resconnect_api_key',
        'resconnect_base_url',
        'nightsbridge_bbid',
        'nightsbridge_api_key',
        'nightsbridge_base_url',
        'hopecloud_api_key',
        'hopecloud_account_id',
        'hopecloud_base_url',
        'wetu_api_key',
    ];

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\Group::make()
                ->schema(function (Get $get, ?Listing $record) {
                    $partnerId = $get('partner_id') ?: $record?->partner_id;
                    $partner = $partnerId ? Partner::find($partnerId) : null;

                    if (! $partner instanceof Partner) {
                        return [
                            Forms\Components\Placeholder::make('no_partner')
                                ->label('')
                                ->content('Please assign and save a partner first — the booking system for this listing can then be connected.'),
                        ];
                    }

                    return $partner->connector_type === null
                        ? self::wizard()
                        : self::statusAndField($partner);
                }),
        ];
    }

    /**
     * Extracts the wizard's connector_setup_type/credential pseudo-fields
     * out of a form's raw $data, writes them onto the given partner (if a
     * connector type was actually chosen), and strips them from the
     * returned array so they're never passed to Listing::create()/update().
     * Call from mutateFormDataBeforeSave()/mutateFormDataBeforeCreate() on
     * every Listing resource page that embeds schema() above.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function persistPartnerFields(array $data, ?int $partnerId): array
    {
        $type = self::connectorTypeValue($data['connector_setup_type'] ?? null);

        if ($type !== null && $partnerId !== null) {
            $partner = Partner::find($partnerId);

            // setConnectorSetup(), not a plain fill()->save() of
            // collapseConfigForSave()'s output: whoever is filling in this
            // wizard (admin panel vs. partner portal) decides whether the
            // credentials are trusted immediately or need staff review first
            // — see Partner::setConnectorSetup() and ConnectorFactory's gate.
            $config = self::collapseConfigForSave($data);
            $isAdmin = auth()->check() && auth()->user()->is_admin;
            $partner?->setConnectorSetup($config['connector_type'], $config['connector_config'], $isAdmin);
            $partner?->save();
        }

        foreach (self::PSEUDO_FIELDS as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private static function wizard(): array
    {
        return [
            Forms\Components\Wizard::make([
                Forms\Components\Wizard\Step::make('connector')
                    ->label('Choose booking system')
                    ->schema(self::connectorFields()),
                Forms\Components\Wizard\Step::make('connect')
                    ->label('Connect this listing')
                    ->schema(fn (Get $get) => self::propertyFields(self::connectorTypeValue($get('connector_setup_type')))),
            ])->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private static function connectorFields(): array
    {
        return [
            Forms\Components\Select::make('connector_setup_type')
                ->label('Booking system')
                ->options(collect(ConnectorType::cases())->mapWithKeys(
                    fn (ConnectorType $c) => [$c->value => $c->label()]
                ))
                ->placeholder('Please select…')
                ->live()
                ->native(false)
                ->dehydrated(),

            Forms\Components\TextInput::make('resconnect_api_key')
                ->label('API key')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::ResConnect->value)
                ->required(fn (Get $get) => $get('connector_setup_type') === ConnectorType::ResConnect->value),
            Forms\Components\TextInput::make('resconnect_base_url')
                ->label('Base URL (optional)')
                ->helperText('Only fill in if it differs from the default.')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::ResConnect->value),

            Forms\Components\TextInput::make('nightsbridge_bbid')
                ->label('Booking Bureau ID (bbid)')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::NightsBridge->value)
                ->required(fn (Get $get) => $get('connector_setup_type') === ConnectorType::NightsBridge->value),
            Forms\Components\TextInput::make('nightsbridge_api_key')
                ->label('API key')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::NightsBridge->value)
                ->required(fn (Get $get) => $get('connector_setup_type') === ConnectorType::NightsBridge->value),
            Forms\Components\TextInput::make('nightsbridge_base_url')
                ->label('Base URL (optional)')
                ->helperText('Only fill in if it differs from the default.')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::NightsBridge->value),

            Forms\Components\TextInput::make('hopecloud_api_key')
                ->label('API key')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::HopeCloud->value)
                ->required(fn (Get $get) => $get('connector_setup_type') === ConnectorType::HopeCloud->value),
            Forms\Components\TextInput::make('hopecloud_account_id')
                ->label('Account ID')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::HopeCloud->value)
                ->required(fn (Get $get) => $get('connector_setup_type') === ConnectorType::HopeCloud->value),
            Forms\Components\TextInput::make('hopecloud_base_url')
                ->label('Base URL (optional)')
                ->helperText('Only fill in if it differs from the default.')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::HopeCloud->value),

            Forms\Components\TextInput::make('wetu_api_key')
                ->label('API key')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::Wetu->value)
                ->required(fn (Get $get) => $get('connector_setup_type') === ConnectorType::Wetu->value),

            Forms\Components\Placeholder::make('native_note')
                ->label('')
                ->content('No API access needed — bookings are checked and held live against NamibWay\'s own availability, using the room types you set up in the next step.')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::Native->value),
            Forms\Components\Placeholder::make('nwr_note')
                ->label('')
                ->content('No API access needed — NWR has no system we can connect to. Every request goes to the team as "manual review".')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::Nwr->value),
            Forms\Components\Placeholder::make('manual_note')
                ->label('')
                ->content('No API access — requests are sent to the partner as a plain email notification, without live availability checks.')
                ->visible(fn (Get $get) => $get('connector_setup_type') === ConnectorType::Manual->value),
        ];
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private static function propertyFields(?string $type): array
    {
        return match ($type) {
            ConnectorType::ResConnect->value, ConnectorType::NightsBridge->value, ConnectorType::HopeCloud->value => [
                Forms\Components\TextInput::make('connector_property_code')
                    ->label('Property Code')
                    ->helperText('The property/unit identifier for this listing in the partner\'s booking system — found in that provider\'s partner portal.')
                    ->maxLength(100),
            ],
            ConnectorType::Wetu->value => [
                Forms\Components\TextInput::make('wetu_id')
                    ->label('Wetu Property ID')
                    ->helperText('Enables automatic content import from Wetu (name, description, photos, region).')
                    ->maxLength(100),
            ],
            ConnectorType::Native->value => [
                Forms\Components\Repeater::make('roomTypes')
                    ->relationship()
                    ->label('Room types')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(50)
                            ->helperText('Stable identifier, e.g. "standard-double" — don\'t change once bookings exist.'),
                        Forms\Components\TextInput::make('total_units')
                            ->label('Units available')
                            ->numeric()
                            ->required()
                            ->minValue(1),
                        Forms\Components\TextInput::make('rate_per_night')
                            ->numeric()
                            ->required()
                            ->prefix('NAD')
                            ->minValue(0),
                        Forms\Components\TextInput::make('max_adults')
                            ->numeric()
                            ->default(2)
                            ->minValue(1),
                        Forms\Components\TextInput::make('max_children')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Forms\Components\Textarea::make('description')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->addActionLabel('Add room type')
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->columnSpanFull(),
            ],
            default => [
                Forms\Components\Placeholder::make('nothing_needed')
                    ->label('')
                    ->content('No further step is needed for this booking system.'),
            ],
        };
    }

    /** @var array<int, ConnectorType> */
    private const CREDENTIALED_TYPES = [
        ConnectorType::ResConnect,
        ConnectorType::NightsBridge,
        ConnectorType::HopeCloud,
        ConnectorType::Wetu,
    ];

    /**
     * @return array<int, Forms\Components\Component>
     */
    private static function statusAndField(Partner $partner): array
    {
        $hasCredentials = filled($partner->connector_config);
        $status = $partner->connector_type->label().' — '
            .($hasCredentials ? 'API credentials on file' : 'no API credentials yet');

        if (in_array($partner->connector_type, self::CREDENTIALED_TYPES, true)) {
            $status .= ' — '.($partner->connector_verified_at !== null
                ? 'verified '.$partner->connector_verified_at->diffForHumans()
                : 'pending staff review before it goes live');
        }

        return [
            Forms\Components\Placeholder::make('connector_status')
                ->label('Connected booking system')
                ->content($status),
            ...self::propertyFields($partner->connector_type->value),
        ];
    }

    private static function connectorTypeValue(mixed $type): ?string
    {
        return $type instanceof ConnectorType ? $type->value : $type;
    }

    /**
     * Collapses the flat wizard pseudo-fields into the single
     * connector_config JSON blob + connector_type — the two real Partner
     * columns — ready for a plain Partner::update().
     *
     * @param  array<string, mixed>  $data
     * @return array{connector_type: ?string, connector_config: array<string, mixed>}
     */
    private static function collapseConfigForSave(array $data): array
    {
        $type = self::connectorTypeValue($data['connector_setup_type'] ?? null);

        $config = match ($type) {
            ConnectorType::ResConnect->value => array_filter([
                'api_key' => $data['resconnect_api_key'] ?? null,
                'base_url' => $data['resconnect_base_url'] ?? null,
            ], fn ($v) => filled($v)),
            ConnectorType::NightsBridge->value => array_filter([
                'bbid' => $data['nightsbridge_bbid'] ?? null,
                'api_key' => $data['nightsbridge_api_key'] ?? null,
                'base_url' => $data['nightsbridge_base_url'] ?? null,
            ], fn ($v) => filled($v)),
            ConnectorType::HopeCloud->value => array_filter([
                'api_key' => $data['hopecloud_api_key'] ?? null,
                'account_id' => $data['hopecloud_account_id'] ?? null,
                'base_url' => $data['hopecloud_base_url'] ?? null,
            ], fn ($v) => filled($v)),
            ConnectorType::Wetu->value => array_filter([
                'api_key' => $data['wetu_api_key'] ?? null,
            ], fn ($v) => filled($v)),
            default => [],
        };

        return [
            'connector_type' => $type,
            'connector_config' => $config,
        ];
    }
}
