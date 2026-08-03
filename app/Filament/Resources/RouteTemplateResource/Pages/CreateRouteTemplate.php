<?php

namespace App\Filament\Resources\RouteTemplateResource\Pages;

use App\Filament\Concerns\HasCreateFormActionsInHeader;
use App\Filament\Resources\RouteTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\Translatable;

class CreateRouteTemplate extends CreateRecord
{
    use HasCreateFormActionsInHeader;
    use Translatable;

    protected static string $resource = RouteTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\LocaleSwitcher::make(),
        ]);
    }
}
