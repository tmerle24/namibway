<?php

namespace App\Filament\Resources\SupplyPointResource\Pages;

use App\Filament\Resources\SupplyPointResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Concerns\Translatable;

class ListSupplyPoints extends ListRecords
{
    use Translatable;

    protected static string $resource = SupplyPointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\LocaleSwitcher::make(),
            Actions\CreateAction::make(),
        ];
    }
}
