<?php

namespace App\Filament\Resources\ListingResource\RelationManagers;

use App\Filament\Support\BookableUnitSchema;
use App\Http\Controllers\Controller;
use App\Models\BookableUnit;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Room/unit types for a listing — the real bookable inventory behind the trip
 * plan's room picker and the Native connector's availability logic.
 *
 * There was no admin UI for these at all until now: `bookable_units` could only be
 * populated by seeder or tinker, which is why every listing in production has
 * none, and why the picker was showing invented tiers instead. Availability is
 * derived rather than stored (see App\Services\Booking\RoomAvailability), so
 * what's edited here is capacity and rate — never a calendar.
 *
 * This is the partner panel's surface. /admin edits the same rows inside the
 * listing form's "Room types" tab instead (a relationship Repeater), because a
 * relation manager can only ever be rendered underneath the form — see
 * ListingResource::getRelations(). Both read their fields from
 * BookableUnitSchema, so the two cannot drift apart.
 */
class BookableUnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookableUnits';

    protected static ?string $title = 'Room types';

    public function form(Form $form): Form
    {
        return $form
            ->schema(BookableUnitSchema::schema())
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\ImageColumn::make('gallery.0')
                    ->label('')
                    ->height(40)
                    ->getStateUsing(fn (BookableUnit $record) => filled($record->gallery[0] ?? null)
                        ? Controller::resolveMediaUrl($record->gallery[0])
                        : null),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('code')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('occupancy')
                    ->label('Sleeps')
                    ->getStateUsing(fn (BookableUnit $record) => $record->max_children > 0
                        ? "{$record->max_adults} + {$record->max_children}"
                        : (string) $record->max_adults),
                Tables\Columns\TextColumn::make('total_units')
                    ->label('Units'),
                Tables\Columns\TextColumn::make('base_rate')
                    ->label('Per night')
                    ->formatStateUsing(fn (BookableUnit $record) => "{$record->currency} ".number_format((float) $record->base_rate, 2)),
                Tables\Columns\TextColumn::make('amenities_count')
                    ->label('Amenities')
                    ->counts('amenities')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('base_rate');
    }
}
