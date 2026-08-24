<?php

namespace App\Filament\Resources\SupplyPointResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\SupplyPointResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;

class EditSupplyPoint extends EditRecord
{
    use HasFormActionsInHeader;
    use Translatable;

    protected static string $resource = SupplyPointResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
            Actions\DeleteAction::make(),
        ]);
    }
}
