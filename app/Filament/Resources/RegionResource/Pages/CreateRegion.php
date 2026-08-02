<?php

namespace App\Filament\Resources\RegionResource\Pages;

use App\Filament\Concerns\HasCreateFormActionsInHeader;
use App\Filament\Resources\RegionResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\Translatable;

class CreateRegion extends CreateRecord
{
    use HasCreateFormActionsInHeader;
    use Translatable;

    protected static string $resource = RegionResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
        ]);
    }
}
