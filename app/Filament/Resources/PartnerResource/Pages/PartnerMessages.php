<?php

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Concerns\HasPartnerMessagesTable;
use App\Filament\Resources\PartnerResource;
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
 * Standalone "Messages" page for one partner — the full cumulated thread
 * across all of that partner's listings (see Partner::messages()), reached
 * via the message icon in PartnerResource's table. See ListingMessages for
 * why this is a dedicated page rather than a tab/RelationManager on the
 * edit form.
 */
class PartnerMessages extends Page implements HasTable
{
    use HasPartnerMessagesTable, InteractsWithTable {
        HasPartnerMessagesTable::table insteadof InteractsWithTable;
    }
    use InteractsWithRecord;

    protected static string $resource = PartnerResource::class;

    protected static string $view = 'filament.resources.partner-resource.pages.partner-messages';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->markUnreadMessagesAsRead();
    }

    public function getTitle(): string|Htmlable
    {
        return "Messages — {$this->getPartnerRecord()->name}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back to partner')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => PartnerResource::getUrl('edit', ['record' => $this->getPartnerRecord()])),
        ];
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
        $partner = $this->getRecord();

        return $partner;
    }
}
