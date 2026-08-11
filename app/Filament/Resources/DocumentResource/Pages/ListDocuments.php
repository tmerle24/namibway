<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentCategoryResource;
use App\Filament\Resources\DocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add document'),
            Actions\Action::make('folders')
                ->label('Folders')
                ->icon('heroicon-o-folder')
                ->color('gray')
                ->url(DocumentCategoryResource::getUrl('index')),
        ];
    }
}
