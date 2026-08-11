<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Filament\Support\DocumentFormData;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /** @var User|null $user */
        $user = auth()->user();

        return DocumentFormData::prepare($data, $user, creating: true);
    }

    protected function getRedirectUrl(): string
    {
        // Straight to the document rather than back to the list: the next thing
        // somebody does after filing one is check it, or say something about it.
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
