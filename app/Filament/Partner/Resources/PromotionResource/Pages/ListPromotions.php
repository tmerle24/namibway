<?php

namespace App\Filament\Partner\Resources\PromotionResource\Pages;

use App\Filament\Partner\Resources\PromotionResource;
use App\Filament\Partner\Support\SelectedProperty;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPromotions extends ListRecords
{
    protected static string $resource = PromotionResource::class;

    public function getSubheading(): ?string
    {
        return app(SelectedProperty::class)->current()?->name;
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New offer')];
    }
}
