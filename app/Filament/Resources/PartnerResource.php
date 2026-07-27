<?php

namespace App\Filament\Resources;

use App\Enums\ConnectorType;
use App\Filament\Resources\PartnerResource\Pages;
use App\Models\Partner;
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
                Forms\Components\FileUpload::make('logo')
                    ->image()
                    ->disk('public')
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

                Forms\Components\Section::make('Booking Connector')
                    ->description('Connect this partner to their property management system for live availability and reservations.')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Select::make('connector_type')
                            ->label('Connector')
                            ->options(collect(ConnectorType::cases())->mapWithKeys(
                                fn (ConnectorType $c) => [$c->value => $c->label()]
                            ))
                            ->placeholder('None (manual handling)')
                            ->live()
                            ->native(false),

                        Forms\Components\TextInput::make('connector_property_code')
                            ->label('Property Code')
                            ->helperText('The property identifier in the partner\'s PMS (e.g. ResRequest property code).')
                            ->maxLength(100)
                            ->visible(fn (Get $get) => filled($get('connector_type'))),

                        Forms\Components\KeyValue::make('connector_config')
                            ->label('Connector Config')
                            ->helperText('Key/value pairs stored encrypted. ResConnect: api_key, base_url (opt). NightsBridge: bbid, api_key. hopeCloud: api_key, account_id. Wetu: api_key.')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->visible(fn (Get $get) => filled($get('connector_type'))),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->disk('public')
                    ->circular(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone'),
                Tables\Columns\TextColumn::make('listings_count')
                    ->counts('listings')
                    ->label('Listings'),
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
