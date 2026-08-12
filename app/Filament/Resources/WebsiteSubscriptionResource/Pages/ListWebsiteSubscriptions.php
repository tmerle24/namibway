<?php

namespace App\Filament\Resources\WebsiteSubscriptionResource\Pages;

use App\Filament\Resources\WebsiteSubscriptionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListWebsiteSubscriptions extends ListRecords
{
    protected static string $resource = WebsiteSubscriptionResource::class;

    /**
     * No create button. A subscription starts because a business asked for one
     * — a row typed in here would be a customer nobody has spoken to.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
