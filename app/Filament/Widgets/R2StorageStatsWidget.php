<?php

namespace App\Filament\Widgets;

use App\Services\R2StorageCostEstimator;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class R2StorageStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $estimate = app(R2StorageCostEstimator::class)->estimate();

        $stats = [
            Stat::make('R2 Storage (Cumulative)', number_format($estimate['total_gb'], 2).' GB')
                ->description('Est. monthly cost: $'.number_format($estimate['estimated_monthly_cost'], 2).' (storage only, not billing-accurate)')
                ->color($estimate['estimated_monthly_cost'] > 0 ? 'warning' : 'success'),
        ];

        foreach ($estimate['breakdown'] as $bucket) {
            $stats[] = Stat::make($bucket['label'], number_format($bucket['bytes'] / 1_073_741_824, 2).' GB');
        }

        return $stats;
    }
}
