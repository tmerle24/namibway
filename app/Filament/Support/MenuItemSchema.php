<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\MenuItem;
use Filament\Forms;
use Illuminate\Database\Eloquent\Model;

/**
 * The fields that describe one menu item, defined once for the two places they
 * are edited: the "Menu" tab of the admin listing form (a relationship
 * Repeater) and MenuItemsRelationManager's modal in the partner panel.
 *
 * The same arrangement — and the same reason — as `BookableUnitSchema`: a
 * restaurant typing its own prices and a content manager checking them have to
 * be looking at the same form, or one of them is working from a field the other
 * cannot see.
 */
class MenuItemSchema
{
    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->helperText('As it reads on the menu, e.g. "Oryx fillet with rosemary butter".')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Forms\Components\Textarea::make('description')
                ->rows(2)
                ->helperText('Optional. What is in it, how it is served — the line under the name.')
                ->maxLength(1000)
                ->columnSpanFull(),
            // Free text with the existing sections offered as suggestions:
            // typing "Starters" on the second dish should complete, and
            // inventing "Braai board" should not need us.
            Forms\Components\TextInput::make('category')
                ->label('Section')
                ->helperText('Starters, Mains, Desserts, Drinks — your own headings. Blank files it under "'.MenuItem::UNCATEGORISED.'".')
                // Not typed to MenuItem: inside the admin Repeater the injected
                // record is the parent Listing, and a row that has not been
                // saved yet has none at all. Both simply offer no suggestions.
                ->datalist(fn (?Model $record): array => self::sections($record))
                ->maxLength(255),
            Forms\Components\TextInput::make('sort')
                ->label('Order')
                ->helperText('Low numbers first, within the section.')
                ->numeric()
                ->minValue(0)
                ->default(0),
            Forms\Components\TextInput::make('price')
                ->helperText('A number. Guests add these up into an order total, so "market price" is not one — take the dish off the menu instead.')
                ->numeric()
                ->minValue(0)
                ->required(),
            Forms\Components\TextInput::make('currency')
                ->helperText('One menu, one currency — an order adds its lines together.')
                ->default('NAD')
                ->required()
                ->maxLength(3),
            Forms\Components\Toggle::make('is_available')
                ->label('On the menu')
                ->helperText('Switch off what the kitchen has run out of. It keeps its place and comes back with one click.')
                ->default(true)
                ->columnSpanFull(),
            Forms\Components\FileUpload::make('image')
                ->label('Photo')
                ->helperText('Optional, one per dish.')
                ->image()
                ->imageEditor()
                ->openable()
                ->disk('r2')
                ->directory('menu-items')
                ->fetchFileInformation(false)
                ->columnSpanFull(),
        ];
    }

    /**
     * The sections this restaurant already uses, so the second dish can be
     * filed under the first one's heading without retyping it exactly.
     *
     * @return array<int, string>
     */
    private static function sections(?Model $record): array
    {
        $listingId = match (true) {
            $record instanceof MenuItem => $record->listing_id,
            $record instanceof Listing => $record->id,
            default => null,
        };

        if ($listingId === null) {
            return [];
        }

        return MenuItem::query()
            ->where('listing_id', $listingId)
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }
}
