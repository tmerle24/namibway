<?php

namespace App\Filament\Resources\DestinationResource\Pages;

use App\Filament\Concerns\HasCreateFormActionsInHeader;
use App\Filament\Resources\DestinationResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\Translatable;

class CreateDestination extends CreateRecord
{
    use HasCreateFormActionsInHeader;
    use Translatable;

    protected static string $resource = DestinationResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
        ]);
    }
}
