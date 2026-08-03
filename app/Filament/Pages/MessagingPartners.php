<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PartnerResource;
use App\Models\Partner;
use App\Models\PartnerMessage;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The same PartnerMessage data as "Messaging > Listings", aggregated per
 * partner instead of shown one row per message — a partner with several
 * listings gets one combined row instead of one per listing (confirmed with
 * the user: not a separate data source, just a different view).
 */
class MessagingPartners extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Messaging';

    protected static ?string $navigationLabel = 'Partners';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Partner messages';

    protected static string $view = 'filament.pages.messaging-partners';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Partner::query()
            ->whereHas('messages', fn (Builder $query) => $query
                ->where('direction', PartnerMessage::DIRECTION_INBOUND)
                ->whereNull('read_at'))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Partner::query()
                ->withCount([
                    'messages',
                    'messages as unread_messages_count' => fn ($query) => $query->inbound()->unread(),
                ])
                ->withMax('messages', 'sent_at'))
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('messages_count')
                    ->label('Messages')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unread_messages_count')
                    ->label('Unread')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('messages_max_sent_at')
                    ->label('Last activity')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('messages_max_sent_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Partner $record): string => PartnerResource::getUrl('edit', ['record' => $record])),
            ])
            ->bulkActions([]);
    }
}
