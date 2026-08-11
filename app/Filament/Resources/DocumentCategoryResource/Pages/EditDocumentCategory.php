<?php

namespace App\Filament\Resources\DocumentCategoryResource\Pages;

use App\Filament\Resources\DocumentCategoryResource;
use App\Models\DocumentCategory;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDocumentCategory extends EditRecord
{
    protected static string $resource = DocumentCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->disabled(fn (DocumentCategory $record): bool => $record->documents()->exists())
                ->tooltip(fn (DocumentCategory $record): ?string => $record->documents()->exists()
                    ? 'Move or delete the documents in this folder first.'
                    : null),
        ];
    }
}
