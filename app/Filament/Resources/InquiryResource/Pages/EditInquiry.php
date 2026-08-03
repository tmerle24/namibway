<?php

namespace App\Filament\Resources\InquiryResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\InquiryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInquiry extends EditRecord
{
    use HasFormActionsInHeader;

    protected static string $resource = InquiryResource::class;

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            Actions\DeleteAction::make(),
        ]);
    }
}
