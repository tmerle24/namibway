<?php

namespace App\Services\Currency;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class CurrencyLocator
{
    /**
     * Resolve the display currency for a request: explicit user preference,
     * then the currency cookie, then a locale/region-based guess.
     */
    public function resolve(Request $request): string
    {
        /** @var list<string> $supported */
        $supported = config('currencies.supported');

        $userCurrency = $request->user()?->preferred_currency;

        if (is_string($userCurrency) && in_array($userCurrency, $supported, true)) {
            return $userCurrency;
        }

        $cookieCurrency = $request->cookie('currency');

        if (is_string($cookieCurrency) && in_array($cookieCurrency, $supported, true)) {
            return $cookieCurrency;
        }

        return $this->guess($request);
    }

    /**
     * Guess a currency from the Accept-Language region subtag (e.g. "en-NA")
     * and, failing that, the resolved UI language. Not real geolocation.
     */
    public function guess(Request $request): string
    {
        foreach ($this->regionCodes($request) as $region) {
            if (in_array($region, config('currencies.african_regions', []), true)) {
                return 'NAD';
            }

            if (in_array($region, config('currencies.gbp_regions', []), true)) {
                return 'GBP';
            }

            if (in_array($region, config('currencies.eu_regions', []), true)) {
                return 'EUR';
            }
        }

        $language = substr(App::getLocale(), 0, 2);

        return config("currencies.language_fallback.{$language}") ?? config('currencies.guess_fallback', 'USD');
    }

    /**
     * @return list<string>
     */
    private function regionCodes(Request $request): array
    {
        $header = (string) $request->header('Accept-Language', '');

        preg_match_all('/[a-z]{2}-([a-z]{2})\b/i', $header, $matches);

        return array_map('strtoupper', $matches[1] ?? []);
    }
}
