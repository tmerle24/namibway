<?php

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\PartnerResource;
use App\Filament\Support\CreateWebsiteFromPartnerAction;
use App\Filament\Support\EditPartnerBlocksAction;
use App\Filament\Support\ImportInstagramPhotosAction;
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
            ImportInstagramPhotosAction::make(),
            EditPartnerBlocksAction::make(),
            CreateWebsiteFromPartnerAction::visit(),
            CreateWebsiteFromPartnerAction::make(),
            Actions\DeleteAction::make(),
        ]);
    }

    /**
     * Staff editing this tab directly counts as reviewing whatever
     * connector_config holds (including credentials the partner submitted
     * themselves via the portal or the token-based listing editor) — so
     * saving here always (re-)verifies it. See Partner::setConnectorSetup()
     * and ConnectorFactory's gate.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['connector_verified_at'] = filled($data['connector_type'] ?? null) ? now() : null;

        return $data;
    }
}
