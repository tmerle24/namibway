<?php

namespace App\Filament\Resources\AttractionResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\AttractionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;

class EditAttraction extends EditRecord
{
    use HasFormActionsInHeader;
    use Translatable;

    protected static string $resource = AttractionResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
            Actions\DeleteAction::make(),
        ]);
    }
}
