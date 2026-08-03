<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ListingResource;
use App\Filament\Resources\PartnerResource;
use App\Models\PartnerMessage;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Flat, chronological inbox of every inbound AND outbound partner/listing
 * message — "Messaging > Listings" in the sidebar. Links out to the real
 * thread (the Listing's or Partner's own Messages tab, see
 * ListingMessagesPanel / PartnerMessagesPanel) rather than duplicating reply
 * actions here. The nav badge only counts unread inbound messages — sent
 * (outbound) messages never need a reader's attention.
 */
class MessagingListings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Messaging';

    protected static ?string $navigationLabel = 'Listings';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Listing messages';

    protected static string $view = 'filament.pages.messaging-listings';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = PartnerMessage::query()->inbound()->unread()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => PartnerMessage::query()->with(['partner', 'listing']))
            ->recordTitleAttribute('subject')
            ->columns([
                Tables\Columns\IconColumn::make('read_at')
                    ->label('')
                    ->icon(fn (PartnerMessage $record): string => match (true) {
                        $record->direction === PartnerMessage::DIRECTION_OUTBOUND => 'heroicon-o-paper-airplane',
                        $record->read_at !== null => 'heroicon-o-envelope-open',
                        default => 'heroicon-o-envelope',
                    })
                    ->color(fn (PartnerMessage $record): string => $record->direction === PartnerMessage::DIRECTION_OUTBOUND || $record->read_at !== null
                        ? 'gray'
                        : 'danger')
                    ->tooltip(fn (PartnerMessage $record): string => $record->direction === PartnerMessage::DIRECTION_OUTBOUND
                        ? 'Sent'
                        : ($record->read_at !== null ? 'Read' : 'Unread')),
                Tables\Columns\TextColumn::make('partner.name')
                    ->label('Partner')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('listing.name')
                    ->label('Listing')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject')
                    ->wrap()
                    ->searchable()
                    ->weight(fn (PartnerMessage $record): ?FontWeight => $record->direction === PartnerMessage::DIRECTION_INBOUND && $record->read_at === null
                        ? FontWeight::Bold
                        : null),
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('direction')
                    ->options([
                        PartnerMessage::DIRECTION_INBOUND => 'Incoming',
                        PartnerMessage::DIRECTION_OUTBOUND => 'Outgoing',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (PartnerMessage $record): string => $record->listing_id !== null
                        ? ListingResource::getUrl('edit', ['record' => $record->listing_id])
                        : PartnerResource::getUrl('edit', ['record' => $record->partner_id])),
                Tables\Actions\Action::make('mark_as_read')
                    ->label('Mark as read')
                    ->icon('heroicon-o-check')
                    ->visible(fn (PartnerMessage $record): bool => $record->direction === PartnerMessage::DIRECTION_INBOUND
                        && $record->read_at === null)
                    ->action(function (PartnerMessage $record): void {
                        $record->update(['read_at' => now()]);

                        Notification::make()->title('Marked as read')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }
}
