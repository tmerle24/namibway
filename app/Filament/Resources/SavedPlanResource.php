<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SavedPlanResource\Pages;
use App\Models\SavedPlan;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SavedPlanResource extends Resource
{
    protected static ?string $model = SavedPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark';

    protected static ?string $navigationLabel = 'Saved Plans';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('token')
                    ->label('Token')
                    ->copyable()
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('title')
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('Guest'),
                Tables\Columns\TextColumn::make('session_id')
                    ->label('Session')
                    ->limit(16)
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->url(fn (SavedPlan $record) => route('trip.show', $record->token))
                    ->openUrlInNewTab()
                    ->icon('heroicon-o-arrow-top-right-on-square'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSavedPlans::route('/'),
        ];
    }
}
