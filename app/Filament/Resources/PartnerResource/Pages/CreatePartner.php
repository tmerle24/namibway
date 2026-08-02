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
}
