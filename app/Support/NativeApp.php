<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Is this request coming from the iOS/Android shell rather than a browser or
 * the PWA?
 *
 * The shells load namibway.com in a WebView, so nothing about the request says
 * "app" on its own. Capacitor's `appendUserAgent` (capacitor.config.ts) puts a
 * marker in the user agent for exactly this — which means a shell built before
 * that setting existed is invisible here, and the client-side check in
 * resources/js/composables/useIsApp.ts is what covers those.
 */
class NativeApp
{
    public const USER_AGENT_MARKER = 'NamibWayApp';

    public static function isRequest(Request $request): bool
    {
        return str_contains((string) $request->userAgent(), self::USER_AGENT_MARKER);
    }
}
