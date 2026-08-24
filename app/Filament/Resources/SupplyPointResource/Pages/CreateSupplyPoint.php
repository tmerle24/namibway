<?php

namespace App\Filament\Resources\SupplyPointResource\Pages;

use App\Filament\Resources\SupplyPointResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\Translatable;

class CreateSupplyPoint extends CreateRecord
{
    use Translatable;

    protected static string $resource = SupplyPointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\LocaleSwitcher::make(),
        ];
    }
}
