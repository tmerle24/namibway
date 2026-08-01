<?php

namespace App\Filament\Resources\ApiClientResource\RelationManagers;

use App\Models\ApiClient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('last_used_at')
                    ->dateTime()
                    ->placeholder('Never used'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->label('Issued'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('issue')
                    ->label('Issue token')
                    ->icon('heroicon-o-plus')
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('Token name')
                            ->helperText('A label to tell tokens apart later, e.g. "production" or "staging".')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        /** @var ApiClient $client */
                        $client = $this->getOwnerRecord();

                        $newToken = $client->createToken($data['name']);

                        Notification::make()
                            ->title('API token issued')
                            ->body('Copy it now — it will not be shown again: '.$newToken->plainTextToken)
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Revoke'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Revoke selected'),
                ]),
            ]);
    }
}
