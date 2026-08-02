<?php

namespace App\Livewire;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Listing edit page's "Messages" tab — scoped to this listing's partner
 * (see Listing::partnerMessages()). Embedded as a form Tab, not a Filament
 * RelationManager, so it sits as a peer of Basic information/Media/etc. in
 * the same tab bar (see ListingResource::form()).
 */
class ListingMessagesPanel extends AbstractPartnerContactPanel
{
    public Listing $record;

    protected function getPartner(): ?Partner
    {
        return $this->record->partner;
    }

    /**
     * @return HasMany<PartnerMessage, Listing>
     */
    protected function getMessagesQuery(): HasMany
    {
        return $this->record->partnerMessages();
    }

    protected function getDefaultContactSubject(): string
    {
        return "About your listing on NamibWay: {$this->record->name}";
    }

    protected function getContactListingId(): ?int
    {
        return $this->record->id;
    }
}
