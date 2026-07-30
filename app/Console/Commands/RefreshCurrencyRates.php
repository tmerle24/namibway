<?php

namespace App\Console\Commands;

use App\Services\Currency\ExchangeRateService;
use Illuminate\Console\Command;

class RefreshCurrencyRates extends Command
{
    protected $signature = 'currency:refresh-rates';

    protected $description = 'Refresh cached NAD/ZAR exchange rates used to display prices in the traveler\'s currency';

    public function handle(ExchangeRateService $rates): int
    {
        $fresh = $rates->refresh();

        $this->info('Currency rates refreshed: '.json_encode($fresh));

        return self::SUCCESS;
    }
}
