<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Admin/Partner Filament panels are English-only (see CLAUDE.md), unlike
 * the traveler-facing site which resolves locale per-request via SetLocale.
 * Panels don't run that middleware, so without this they'd fall back to
 * config('app.locale') as configured on the server — which can silently
 * differ per environment and isn't tied to what these panels actually
 * support content-wise.
 */
class ForceAdminLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale('en');

        return $next($request);
    }
}
