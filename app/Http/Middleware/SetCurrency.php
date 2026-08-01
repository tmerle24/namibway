<?php

namespace App\Http\Middleware;

use App\Services\Currency\CurrencyLocator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrency
{
    public function __construct(private readonly CurrencyLocator $currencyLocator) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->instance('currentCurrency', $this->currencyLocator->resolve($request));

        return $next($request);
    }
}
