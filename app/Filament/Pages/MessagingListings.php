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
 * Flat, chronological inbox of every inbound partner/listing message —
 * "Messaging > Listings" in the sidebar. Links out to the real thread (the
 * Listing's or Partner's own Messages tab, see ListingMessagesPanel /
 * PartnerMessagesPanel) rather than duplicating reply actions here.
 */
class MessagingListings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Messaging';

    protected static ?string $navigationLabel = 'Listings';

    protected static ?string $title = 'Listing messages';

    protected static string $view = 'filament.pages.messaging-listings';

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
            ->query(fn () => PartnerMessage::query()->inbound()->with(['partner', 'listing']))
            ->recordTitleAttribute('subject')
            ->columns([
                Tables\Columns\IconColumn::make('read_at')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-o-envelope-open')
                    ->falseIcon('heroicon-o-envelope')
                    ->trueColor('gray')
                    ->falseColor('danger'),
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
                    ->weight(fn (PartnerMessage $record): ?FontWeight => $record->read_at === null
                        ? FontWeight::Bold
                        : null),
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('sent_at', 'desc')
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
                    ->visible(fn (PartnerMessage $record): bool => $record->read_at === null)
                    ->action(function (PartnerMessage $record): void {
                        $record->update(['read_at' => now()]);

                        Notification::make()->title('Marked as read')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }
}
