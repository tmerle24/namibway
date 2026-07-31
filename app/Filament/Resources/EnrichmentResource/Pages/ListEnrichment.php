<?php

namespace App\Filament\Resources\EnrichmentResource\Pages;

use App\Filament\Resources\EnrichmentResource;
use App\Filament\Widgets\ListingEnrichmentStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListEnrichment extends ListRecords
{
    protected static string $resource = EnrichmentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ListingEnrichmentStatsWidget::class,
        ];
    }
}
