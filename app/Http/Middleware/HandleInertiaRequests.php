<?php

namespace App\Http\Middleware;

use App\Services\Currency\ExchangeRateService;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'locale' => app()->getLocale(),
            'availableLocales' => config('locales.labels'),
            'currency' => app('currentCurrency'),
            'availableCurrencies' => config('currencies.labels'),
            'currencyRates' => app(ExchangeRateService::class)->rates(),
            // Deployment config, not per-request state — the frontend needs it
            // because only a component knows how wide its own image slot is.
            'mediaTransforms' => MediaUrl::clientConfig(),
        ];
    }
}
