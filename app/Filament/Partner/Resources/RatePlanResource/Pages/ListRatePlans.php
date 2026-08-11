<?php

namespace App\Filament\Partner\Resources\RatePlanResource\Pages;

use App\Filament\Partner\Resources\RatePlanResource;
use App\Filament\Partner\Support\SelectedProperty;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRatePlans extends ListRecords
{
    protected static string $resource = RatePlanResource::class;

    public function getSubheading(): ?string
    {
        return app(SelectedProperty::class)->current()?->name;
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New rate plan')];
    }
}
