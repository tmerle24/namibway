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
     * Opened from the explorer with `?folder=`, which is the folder the person
     * was standing in. Written into the already-filled form rather than into
     * the field's default, so the rest of the defaults (the kind, above all)
     * survive — and so that arriving here without a folder still means the top
     * level rather than a missing key.
     */
    public function mount(): void
    {
        parent::mount();

        $folder = request()->query('folder');

        if (is_numeric($folder) && is_array($this->data)) {
            $this->data['document_category_id'] = (int) $folder;
        }
    }

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
