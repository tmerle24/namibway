<?php

namespace App\Filament\Resources\ListingResource\Pages;

use App\Filament\Concerns\HasPartnerMessagesTable;
use App\Filament\Resources\ListingResource;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Standalone "Messages" page for one listing — reached via the message icon
 * in ListingResource's table, not a tab on the edit form. A dedicated page
 * (own URL, no embedded form) rather than a RelationManager/embedded
 * Livewire component on the edit page itself: keeps the record's own edit
 * <form> free of a nested Filament table, which has its own action-modal
 * <form> wrappers that broke form submission when embedded directly.
 */
class ListingMessages extends Page implements HasTable
{
    use HasPartnerMessagesTable, InteractsWithTable {
        HasPartnerMessagesTable::table insteadof InteractsWithTable;
    }
    use InteractsWithRecord;

    protected static string $resource = ListingResource::class;

    protected static string $view = 'filament.resources.listing-resource.pages.listing-messages';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->markUnreadMessagesAsRead();
    }

    public function getTitle(): string|Htmlable
    {
        return "Messages — {$this->getListing()->name}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back to listing')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => ListingResource::getUrl('edit', ['record' => $this->getListing()])),
        ];
    }

    protected function getPartner(): ?Partner
    {
        return $this->getListing()->partner;
    }

    /**
     * @return HasMany<PartnerMessage, Listing>
     */
    protected function getMessagesQuery(): HasMany
    {
        return $this->getListing()->partnerMessages();
    }

    protected function getDefaultContactSubject(): string
    {
        return "About your listing on NamibWay: {$this->getListing()->name}";
    }

    protected function getContactListing(): ?Listing
    {
        return $this->getListing();
    }

    private function getListing(): Listing
    {
        /** @var Listing $listing */
        $listing = $this->getRecord();

        return $listing;
    }
}
