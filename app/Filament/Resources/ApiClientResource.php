<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiClientResource\Pages;
use App\Filament\Resources\ApiClientResource\RelationManagers\TokensRelationManager;
use App\Models\ApiClient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ApiClientResource extends Resource
{
    protected static ?string $model = ApiClient::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    // Was the sole occupant of an "Interfaces" group, which meant a whole
    // sidebar section for one rarely-touched screen. It configures who may talk
    // to the platform, which is a setting.
    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'API Clients';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->helperText('Which partner/OTA this API access belongs to, e.g. "SafariBookings" or "NamibWay internal widget".')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('contact_email')
                    ->email()
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_active')
                    ->helperText('Turning this off immediately blocks all of this client\'s existing tokens.')
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('contact_email')
                    ->label('Contact'),
                Tables\Columns\TextColumn::make('tokens_count')
                    ->counts('tokens')
                    ->label('Tokens'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
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
            TokensRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiClients::route('/'),
            'create' => Pages\CreateApiClient::route('/create'),
            'edit' => Pages\EditApiClient::route('/{record}/edit'),
        ];
    }
}
