<?php

namespace App\Filament\Resources\RegionResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\RegionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;

class EditRegion extends EditRecord
{
    use HasFormActionsInHeader;
    use Translatable;

    protected static string $resource = RegionResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
            Actions\DeleteAction::make(),
        ]);
    }
}
