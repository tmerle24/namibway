<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\CityResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCity extends EditRecord
{
    use HasFormActionsInHeader;

    protected static string $resource = CityResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\DeleteAction::make(),
        ]);
    }
}
