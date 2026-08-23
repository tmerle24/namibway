<?php

namespace App\Filament\Resources\AttractionResource\Pages;

use App\Filament\Resources\AttractionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Concerns\Translatable;

class ListAttractions extends ListRecords
{
    use Translatable;

    protected static string $resource = AttractionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\LocaleSwitcher::make(),
            Actions\CreateAction::make(),
        ];
    }
}
