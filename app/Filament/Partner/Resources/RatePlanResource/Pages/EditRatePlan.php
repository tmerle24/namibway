<?php

namespace App\Filament\Partner\Resources\RatePlanResource\Pages;

use App\Filament\Partner\Resources\RatePlanResource;
use Filament\Resources\Pages\EditRecord;

class EditRatePlan extends EditRecord
{
    protected static string $resource = RatePlanResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
