<?php

namespace App\Filament\Resources\ApiClientResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\ApiClientResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditApiClient extends EditRecord
{
    use HasFormActionsInHeader;

    protected static string $resource = ApiClientResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\DeleteAction::make(),
        ]);
    }
}
