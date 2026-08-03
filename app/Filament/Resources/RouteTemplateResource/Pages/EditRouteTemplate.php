<?php

namespace App\Filament\Resources\RouteTemplateResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\RouteTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;

class EditRouteTemplate extends EditRecord
{
    use HasFormActionsInHeader;
    use Translatable;

    protected static string $resource = RouteTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
            Actions\DeleteAction::make(),
        ]);
    }
}
