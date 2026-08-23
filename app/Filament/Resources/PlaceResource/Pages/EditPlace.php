<?php

namespace App\Filament\Resources\PlaceResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\PlaceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;

class EditPlace extends EditRecord
{
    use HasFormActionsInHeader;
    use Translatable;

    protected static string $resource = PlaceResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
            Actions\DeleteAction::make(),
        ]);
    }
}
