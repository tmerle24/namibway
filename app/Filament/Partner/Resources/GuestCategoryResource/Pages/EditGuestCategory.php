<?php

namespace App\Filament\Partner\Resources\GuestCategoryResource\Pages;

use App\Filament\Partner\Resources\GuestCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditGuestCategory extends EditRecord
{
    protected static string $resource = GuestCategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
