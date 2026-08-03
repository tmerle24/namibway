<?php

namespace App\Filament\Resources\ApiClientResource\Pages;

use App\Filament\Resources\ApiClientResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListApiClients extends ListRecords
{
    protected static string $resource = ApiClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
