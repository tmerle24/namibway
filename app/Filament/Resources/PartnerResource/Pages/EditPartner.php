<?php

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\PartnerResource;
use App\Filament\Support\ImportInstagramPhotosAction;
use App\Filament\Support\ManageShopProductsAction;
use App\Models\Partner;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;
use Illuminate\Contracts\Support\Htmlable;

class EditPartner extends EditRecord
{
    use HasFormActionsInHeader;
    use Translatable;

    protected static string $resource = PartnerResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Partner $record */
        $record = $this->getRecord();

        return $record->name;
    }

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
            ImportInstagramPhotosAction::make(),
            ManageShopProductsAction::partnerHeader(),
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
