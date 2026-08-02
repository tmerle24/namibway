<?php

namespace App\Filament\Resources\ReviewResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\ReviewResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReview extends EditRecord
{
    use HasFormActionsInHeader;

    protected static string $resource = ReviewResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\DeleteAction::make(),
        ]);
    }
}
