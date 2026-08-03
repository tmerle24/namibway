<?php

namespace App\Filament\Resources\ApiClientResource\Pages;

use App\Filament\Concerns\HasCreateFormActionsInHeader;
use App\Filament\Resources\ApiClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateApiClient extends CreateRecord
{
    use HasCreateFormActionsInHeader;

    protected static string $resource = ApiClientResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions();
    }
}
