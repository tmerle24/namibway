<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Concerns\HasCreateFormActionsInHeader;
use App\Filament\Resources\CityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCity extends CreateRecord
{
    use HasCreateFormActionsInHeader;

    protected static string $resource = CityResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions();
    }
}
