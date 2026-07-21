<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        /** @var list<string> $supported */
        $supported = config('locales.supported');

        $cookieLocale = $request->cookie('locale');

        if (is_string($cookieLocale) && in_array($cookieLocale, $supported, true)) {
            return $cookieLocale;
        }

        $preferred = $request->getPreferredLanguage($supported);

        if (is_string($preferred)) {
            return $preferred;
        }

        return config('app.fallback_locale', 'en');
    }
}
