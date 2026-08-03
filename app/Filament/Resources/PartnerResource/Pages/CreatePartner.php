<?php

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Concerns\HasCreateFormActionsInHeader;
use App\Filament\Resources\PartnerResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\Translatable;

class CreatePartner extends CreateRecord
{
    use HasCreateFormActionsInHeader;
    use Translatable;

    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
        ]);
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed> */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['connector_verified_at'] = filled($data['connector_type'] ?? null) ? now() : null;

        return $data;
    }
}
