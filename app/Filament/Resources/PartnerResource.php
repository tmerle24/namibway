<?php

namespace App\Filament\Resources;

use App\Enums\ConnectorType;
use App\Filament\Resources\PartnerResource\Pages;
use App\Http\Controllers\Controller;
use App\Livewire\PartnerMessagesPanel;
use App\Models\Partner;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PartnerResource extends Resource
{
    use Translatable;

    protected static ?string $model = Partner::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

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
                                Forms\Components\FileUpload::make('logo')
                                    ->image()
                                    ->disk('r2')
                                    ->directory('partners')
                                    ->imageEditor()
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Textarea::make('bio')
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('phone')
                                    ->tel()
                                    ->maxLength(50),
                                Forms\Components\TextInput::make('website')
                                    ->url()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('instagram')
                                    ->url()
                                    ->maxLength(255)
                                    ->helperText('Full profile URL, e.g. https://instagram.com/yourlodge'),
                                Forms\Components\TextInput::make('facebook')
                                    ->url()
                                    ->maxLength(255),
                            ])
                            ->columns(2),

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
                            ]),

                        // Cumulated thread across all of this partner's listings — only
                        // meaningful once the partner exists, hidden on the Create page.
                        Forms\Components\Tabs\Tab::make('Messages')
                            ->icon('heroicon-o-envelope')
                            ->visible(fn (?Partner $record): bool => $record !== null)
                            ->badge(fn (?Partner $record): ?string => $record?->email === null ? 'No email' : null)
                            ->badgeColor('gray')
                            ->schema([
                                Forms\Components\Livewire::make(PartnerMessagesPanel::class)
                                    ->key('partner-messages-panel'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
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
                //
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
            'index' => Pages\ListPartners::route('/'),
            'create' => Pages\CreatePartner::route('/create'),
            'edit' => Pages\EditPartner::route('/{record}/edit'),
        ];
    }
}
