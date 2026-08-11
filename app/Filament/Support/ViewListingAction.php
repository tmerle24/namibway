<?php

namespace App\Filament\Support;

use App\Models\Listing;
use Filament\Actions\Action as PageAction;

/**
 * "Show me this listing as a traveller sees it", from the editor's header.
 *
 * The table has had this for a long time; the edit page has not, which is the
 * page somebody is actually on when they want to check what they just changed.
 * Opens in a new tab, because the point is to compare — closing the editor to
 * look would lose whatever is half-typed in it.
 */
class ViewListingAction
{
    public static function header(string $name = 'view_listing'): PageAction
    {
        return PageAction::make($name)
            ->label('View on namibway.com')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->tooltip(fn (Listing $record): string => $record->is_published
                ? 'Open the public listing'
                : 'Open the listing — not published yet, so this is the admin preview')
            ->url(fn (Listing $record): string => route('listings.show', $record->slug))
            ->openUrlInNewTab();
    }
}
