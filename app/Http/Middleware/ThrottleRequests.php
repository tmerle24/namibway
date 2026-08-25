<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests as FrameworkThrottleRequests;
use RuntimeException;

/**
 * `throttle:20,1` means twenty requests a minute to *this route*. That is what
 * everybody who writes it believes, and it is not what the framework does.
 *
 * Laravel's signature for an un-prefixed numeric throttle is
 * `sha1($route->getDomain().'|'.$request->ip())` — the domain and the caller,
 * and nothing about the route. So every numerically throttled route in the
 * application shares one counter per caller, and the strictest cap among them
 * governs all of them together. On namibway.com that meant opening the trip
 * plan — region coordinates, city lists, route stops, supply stops, an
 * autosave per edit — spent Kaia's twenty within seconds, and the chat then
 * answered 429 to everything for the rest of the minute. Kaia had stopped
 * replying at all, and the reason was that a *map* had used up her budget.
 *
 * The same arithmetic quietly broke the strictest limits worst: the support
 * and feedback forms are `throttle:5,1`, so five requests of any kind at all —
 * a listing preview, a saved plan being loaded — were enough to refuse them.
 *
 * The framework offers a per-route prefix as the third argument, and that
 * would work: `throttle:20,1,kaia-message`. It is not used here because it has
 * to be remembered on every route anybody ever adds, and forgetting it is
 * silent — the route works, it just quietly shares somebody else's budget.
 * Putting the route into the signature makes it the default instead, which is
 * the same reasoning as the alphabetical navigation manager: an invariant that
 * has to be remembered is one that eventually is not.
 *
 * Named limiters (`throttle:kaia-chat`, `throttle:api`) do not come through
 * here — they build their own keys, namespaced by the limiter's name, and were
 * never affected.
 */
class ThrottleRequests extends FrameworkThrottleRequests
{
    /**
     * @param  Request  $request
     * @return string
     */
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();

        if (! $route) {
            throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
        }

        // Who is asking. An account rather than an address where there is one,
        // exactly as the framework does it — two people behind one office line
        // are two callers, and one person on two devices is one.
        $caller = ($user = $request->user())
            ? 'user|'.$user->getAuthIdentifier()
            : 'ip|'.$request->ip();

        // What they are asking for. The route's name where it has one and its
        // URI pattern otherwise — the pattern, not the path, so every
        // `/kaia/plans/{token}` shares the one budget that the limit on that
        // route is about, rather than one budget per token.
        $target = $route->getDomain().'|'.$request->method().'|'.($route->getName() ?: $route->uri());

        return static::$shouldHashKeys
            ? sha1($target.'|'.$caller)
            : $target.'|'.$caller;
    }
}
