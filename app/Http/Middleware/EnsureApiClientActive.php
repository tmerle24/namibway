<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum only verifies the token itself — a revoked/deactivated ApiClient's
 * existing tokens would otherwise keep working until individually deleted.
 * This rejects requests as soon as the client is toggled inactive in Filament.
 */
class EnsureApiClientActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiClient|null $client */
        $client = $request->user();

        if (! $client || ! $client->is_active) {
            abort(403, 'API client is inactive.');
        }

        return $next($request);
    }
}
