<?php

namespace App\Filament\Resources\RouteTemplateResource\Pages;

use App\Filament\Resources\RouteTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Concerns\Translatable;

class ListRouteTemplates extends ListRecords
{
    use Translatable;

    protected static string $resource = RouteTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\LocaleSwitcher::make(),
            Actions\CreateAction::make(),
        ];
    }
}
