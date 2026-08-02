<?php

namespace App\Livewire;

use App\Models\Partner;
use App\Models\PartnerMessage;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Partner edit page's "Messages" tab — the full cumulated thread across
 * all of this partner's listings (see Partner::messages()), unlike
 * ListingMessagesPanel which only shows one listing's slice of it.
 */
class PartnerMessagesPanel extends AbstractPartnerContactPanel
{
    public Partner $record;

    protected function getPartner(): ?Partner
    {
        return $this->record;
    }

    /**
     * @return HasMany<PartnerMessage, Partner>
     */
    protected function getMessagesQuery(): HasMany
    {
        return $this->record->messages();
    }

    protected function getDefaultContactSubject(): string
    {
        return "Following up — {$this->record->name} on NamibWay";
    }
}
