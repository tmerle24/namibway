<?php

namespace App\Filament\Partner\Resources\ChargeResource\Pages;

use App\Filament\Partner\Resources\ChargeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCharges extends ListRecords
{
    protected static string $resource = ChargeResource::class;

    /**
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a tax or fee')];
    }
}
