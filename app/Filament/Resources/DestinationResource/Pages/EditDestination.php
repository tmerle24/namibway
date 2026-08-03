<?php

namespace App\Filament\Resources\DestinationResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\DestinationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;

class EditDestination extends EditRecord
{
    use HasFormActionsInHeader;
    use Translatable;

    protected static string $resource = DestinationResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
            Actions\DeleteAction::make(),
        ]);
    }
}
