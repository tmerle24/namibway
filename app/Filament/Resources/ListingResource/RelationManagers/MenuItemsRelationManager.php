<?php

namespace App\Filament\Resources\ListingResource\RelationManagers;

use App\Enums\ListingType;
use App\Filament\Support\MenuItemSchema;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\MenuItem;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A restaurant's menu — the items a guest can order from the listing page.
 *
 * This is the partner panel's surface. /admin edits the same rows inside the
 * listing form's "Menu" tab (a relationship Repeater), because a relation
 * manager can only ever be rendered underneath the form — the same arrangement
 * room types have, and both surfaces read their fields from MenuItemSchema so
 * the two cannot drift apart.
 *
 * Hidden for everything that is not a restaurant: a lodge has no menu to enter
 * here, and a tab it can never fill is a tab it has to learn to ignore.
 */
class MenuItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'menuItems';

    protected static ?string $title = 'Menu';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Listing && $ownerRecord->type === ListingType::Restaurant;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(MenuItemSchema::schema())
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('')
                    ->height(40)
                    ->getStateUsing(fn (MenuItem $record) => filled($record->image)
                        ? Controller::resolveMediaUrl($record->image)
                        : null),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Section')
                    ->badge()
                    ->color('gray')
                    ->placeholder(MenuItem::UNCATEGORISED),
                Tables\Columns\TextColumn::make('price')
                    ->formatStateUsing(fn (MenuItem $record) => "{$record->currency} ".number_format($record->price, 2)),
                Tables\Columns\IconColumn::make('is_available')
                    ->label('On the menu')
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add a menu item'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading('Delete this menu item?')
                    ->modalDescription('Orders that already contain it keep their own record of the name and the price. Switching "On the menu" off instead takes it off sale and keeps it here.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            // Menu order, which is what the guest sees — not alphabetical, and
            // not by price.
            ->defaultSort('sort')
            ->reorderable('sort');
    }
}
