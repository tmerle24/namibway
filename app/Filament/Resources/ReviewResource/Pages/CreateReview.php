<?php

namespace App\Filament\Resources\ReviewResource\Pages;

use App\Filament\Concerns\HasCreateFormActionsInHeader;
use App\Filament\Resources\ReviewResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReview extends CreateRecord
{
    use HasCreateFormActionsInHeader;

    protected static string $resource = ReviewResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions();
    }
}
