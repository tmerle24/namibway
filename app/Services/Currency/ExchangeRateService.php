<?php

namespace App\Services\Currency;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExchangeRateService
{
    private const CACHE_KEY = 'currency:rates:zar-base';

    private const CACHE_TTL_SECONDS = 60 * 60 * 24;

    /**
     * Rough, occasionally-updated fallback so prices never look broken if the
     * rate API is unreachable and the cache is cold — better than crashing or
     * silently showing NAD amounts labelled as USD.
     *
     * @var array<string, float>
     */
    private const FALLBACK_RATES = [
        'NAD' => 1.0,
        'ZAR' => 1.0,
        'USD' => 0.055,
        'EUR' => 0.050,
        'GBP' => 0.043,
    ];

    /**
     * @return array<string, float>
     */
    public function rates(): array
    {
        return Cache::store('redis')->remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetch(),
        );
    }

    /**
     * @return array<string, float>
     */
    public function refresh(): array
    {
        $rates = $this->fetch();

        Cache::store('redis')->put(self::CACHE_KEY, $rates, self::CACHE_TTL_SECONDS);

        return $rates;
    }

    public function convert(float $amountNad, string $toCurrency): float
    {
        // NAD is pegged 1:1 to ZAR (Common Monetary Area), so a NAD amount
        // converts using the ZAR rate directly — no separate NAD rate exists
        // on the exchange rate API.
        $rate = $this->rates()[$toCurrency] ?? 1.0;

        return $amountNad * $rate;
    }

    /**
     * @return array<string, float>
     */
    private function fetch(): array
    {
        try {
            // Frankfurter (ECB reference rates) — free, no API key needed.
            $response = Http::timeout(5)->get('https://api.frankfurter.dev/v1/latest', [
                'base' => 'ZAR',
                'symbols' => 'USD,EUR,GBP',
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Unexpected status: '.$response->status());
            }

            /** @var array<string, float> $fetched */
            $fetched = $response->json('rates') ?? [];

            if ($fetched === []) {
                throw new \RuntimeException('Empty rates payload');
            }

            $fetched['ZAR'] = 1.0;
            $fetched['NAD'] = 1.0;

            return array_merge(self::FALLBACK_RATES, $fetched);
        } catch (Throwable $e) {
            Log::warning('Currency rate fetch failed, using fallback rates', [
                'error' => $e->getMessage(),
            ]);

            return self::FALLBACK_RATES;
        }
    }
}
