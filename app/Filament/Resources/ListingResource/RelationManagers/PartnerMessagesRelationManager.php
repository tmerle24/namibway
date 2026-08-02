<?php

namespace App\Filament\Resources\ListingResource\RelationManagers;

use App\Filament\Concerns\HasPartnerMessagesTable;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Listing edit page's "Messages" tab — scoped to this listing's partner
 * (see Listing::partnerMessages()).
 */
class PartnerMessagesRelationManager extends RelationManager
{
    use HasPartnerMessagesTable;

    protected static string $relationship = 'partnerMessages';

    protected static ?string $title = 'Messages';

    protected static ?string $icon = 'heroicon-o-envelope';

    // Always show the tab (rather than hiding it) so editors know it exists —
    // it's just greyed out via the badge below until there's someone to
    // actually email.
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Listing $ownerRecord */
        return $ownerRecord->partner?->email === null ? 'No email' : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'gray';
    }

    public function mount(): void
    {
        parent::mount();

        $this->markUnreadMessagesAsRead();
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
        $listing = $this->getOwnerRecord();

        return $listing;
    }
}
