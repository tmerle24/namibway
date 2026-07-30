<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrency
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->instance('currentCurrency', $this->resolveCurrency($request));

        return $next($request);
    }

    private function resolveCurrency(Request $request): string
    {
        /** @var list<string> $supported */
        $supported = config('currencies.supported');

        $cookieCurrency = $request->cookie('currency');

        if (is_string($cookieCurrency) && in_array($cookieCurrency, $supported, true)) {
            return $cookieCurrency;
        }

        return config('currencies.default', 'NAD');
    }
}
