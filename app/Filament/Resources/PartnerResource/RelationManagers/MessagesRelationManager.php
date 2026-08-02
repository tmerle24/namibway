<?php

namespace App\Filament\Resources\PartnerResource\RelationManagers;

use App\Filament\Concerns\HasPartnerMessagesTable;
use App\Models\Partner;
use App\Models\PartnerMessage;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Partner edit page's "Messages" tab — the full cumulated thread across
 * all of this partner's listings (see Partner::messages()), unlike the
 * Listing edit page's tab which only shows one listing's slice of it.
 */
class MessagesRelationManager extends RelationManager
{
    use HasPartnerMessagesTable;

    protected static string $relationship = 'messages';

    protected static ?string $title = 'Messages';

    protected static ?string $icon = 'heroicon-o-envelope';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Partner $ownerRecord */
        return $ownerRecord->email === null ? 'No email' : null;
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
        return $this->getPartnerRecord();
    }

    /**
     * @return HasMany<PartnerMessage, Partner>
     */
    protected function getMessagesQuery(): HasMany
    {
        return $this->getPartnerRecord()->messages();
    }

    protected function getDefaultContactSubject(): string
    {
        return "Following up — {$this->getPartnerRecord()->name} on NamibWay";
    }

    private function getPartnerRecord(): Partner
    {
        /** @var Partner $partner */
        $partner = $this->getOwnerRecord();

        return $partner;
    }
}
