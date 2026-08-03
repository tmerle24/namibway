<?php

namespace App\Filament\Resources\RegionResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\RegionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRegion extends EditRecord
{
    use HasFormActionsInHeader;

    protected static string $resource = RegionResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\DeleteAction::make(),
        ]);
    }
}
