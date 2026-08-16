<?php

namespace App\Filament\Support;

use App\Enums\ListingType;
use App\Models\Listing;
use Filament\Forms;

/**
 * The two things a restaurant decides for itself: whether it takes table
 * bookings online, and whether it takes orders.
 *
 * Defined once for the two panels that edit them — /admin's Visibility tab and
 * the partner panel's Settings section — for the same reason BookableUnitSchema
 * and MenuItemSchema are shared: a restaurant owner switching ordering off and
 * a content manager checking why the tab vanished have to be looking at the
 * same two fields.
 *
 * Shown only on a restaurant. Every other listing carries the columns and
 * ignores them, and a toggle that does nothing is worse than no toggle — it is
 * a promise the page will not keep.
 */
class RestaurantChannelSchema
{
    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\Toggle::make('accepts_table_reservations')
                ->label('Takes table reservations online')
                ->helperText('Off means walk-ins and telephone only — the reservation form is not offered.')
                ->default(true)
                ->visible(self::isRestaurant(...)),

            Forms\Components\Toggle::make('accepts_orders')
                ->label('Takes orders online')
                ->helperText('Off still shows the menu on the listing — guests can read it, but not order from it.')
                ->default(true)
                ->visible(self::isRestaurant(...)),
        ];
    }

    /**
     * Read from the live form state where there is one, so switching the type
     * to Restaurant reveals the toggles without a save; the saved record is the
     * fallback for the partner panel, whose type field is not on the form at
     * all.
     */
    private static function isRestaurant(Forms\Get $get, ?Listing $record): bool
    {
        $type = $get('type');

        if (is_string($type)) {
            return $type === ListingType::Restaurant->value;
        }

        return $record?->type === ListingType::Restaurant;
    }
}
