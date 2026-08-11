<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Filament\Support\DocumentFormData;
use App\Models\Document;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('download')
                ->label('Open file')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (Document $record): string => route('documents.download', $record))
                ->openUrlInNewTab()
                ->visible(fn (Document $record): bool => $record->isFile()),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User|null $user */
        $user = auth()->user();

        /** @var Document $document */
        $document = $this->getRecord();

        // `kind` is disabled on this form, so it never comes back in the payload
        // — take it from the record instead of defaulting it and wiping the
        // other half of the row.
        $data['kind'] ??= $document->kind;

        return DocumentFormData::prepare($data, $user, creating: false);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
