<?php

namespace App\Filament\Resources\ReleaseNoteResource\Pages;

use App\Filament\Resources\ReleaseNoteResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewReleaseNote extends ViewRecord
{
    protected static string $resource = ReleaseNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
