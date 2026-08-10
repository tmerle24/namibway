<?php

namespace App\Filament\Support;

use Closure;
use Filament\Tables;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Messages" column shared by the admin Listings and Partners tables —
 * one envelope badge per row, linking to that record's Messages page.
 *
 * The envelope is shown on *every* row, whether or not there is an email
 * address to write to. An earlier version hid the icon (and the link with it)
 * whenever the partner had no email on file, which is the normal state for a
 * scraped listing: the importers create a Partner from the source page, but
 * those pages rarely carry an address, so the column was simply blank for most
 * rows and the messages page was unreachable from the table. Blank told the
 * reader nothing — not "no thread", not "nobody to write to" — and blocked the
 * one route to the page that explains which of the two it is. Reachability is
 * decided on the page, not by hiding the way there; the tooltip says up front
 * what the row's state is.
 *
 * Both callers must have selected an `unread_messages_count` aggregate
 * (withCount) on the query, since that is this column's state.
 */
class MessagesColumn
{
    /**
     * @param  Closure(Model): string  $url  Messages page for the row's record.
     * @param  Closure(Model): ?string  $contactEmail  Address the row can be written to, if any.
     */
    public static function make(Closure $url, Closure $contactEmail): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('unread_messages_count')
            ->label('Messages')
            ->badge()
            ->icon('heroicon-o-envelope')
            // A non-breaking space, not '': an empty badge collapses to nothing,
            // and the icon would go with it.
            ->formatStateUsing(fn (int $state): string => $state > 0 ? (string) $state : "\u{00A0}")
            ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
            ->tooltip(fn (int $state, Model $record): string => match (true) {
                $state > 0 => "{$state} unread message(s) — click to view",
                blank($contactEmail($record)) => 'No email on file — click to see the thread',
                default => 'View messages',
            })
            ->url($url)
            ->sortable();
    }
}
