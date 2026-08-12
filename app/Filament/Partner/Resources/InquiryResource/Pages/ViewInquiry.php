<?php

namespace App\Filament\Partner\Resources\InquiryResource\Pages;

use App\Filament\Partner\Resources\InquiryResource;
use App\Filament\Partner\Support\InquiryDecisions;
use Filament\Resources\Pages\ViewRecord;

class ViewInquiry extends ViewRecord
{
    protected static string $resource = InquiryResource::class;

    /**
     * The same three decisions as the list, because this is where somebody
     * reads the request before making one.
     *
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return InquiryDecisions::pageActions();
    }
}
