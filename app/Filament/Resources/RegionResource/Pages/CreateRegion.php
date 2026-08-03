<?php

namespace App\Filament\Resources\RegionResource\Pages;

use App\Filament\Concerns\HasCreateFormActionsInHeader;
use App\Filament\Resources\RegionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRegion extends CreateRecord
{
    use HasCreateFormActionsInHeader;

    protected static string $resource = RegionResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions();
    }
}
