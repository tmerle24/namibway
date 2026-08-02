<?php

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\PartnerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;

class EditPartner extends EditRecord
{
    use HasFormActionsInHeader;
    use Translatable;

    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
            Actions\DeleteAction::make(),
        ]);
    }

    // Puts "Messages" in the same top tab bar as Basic information/Portal
    // Access/etc. instead of rendering the relation manager as its own block
    // below the form (Filament's default).
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Partner details';
    }
}
