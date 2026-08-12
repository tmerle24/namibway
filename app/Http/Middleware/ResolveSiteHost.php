<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Sites\SiteController;
use App\Http\Controllers\Sites\SiteEnquiryController;
use App\Sites\SiteResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a request that arrived on a customer's host to that customer's website.
 *
 * ## Why this is a middleware and not a route file
 *
 * The travel platform's routes carry no host constraint — `Route::get('/',
 * HomeController::class)` answers on whatever host reaches the application. A
 * route file for sites, however it were ordered, would either be shadowed by
 * those routes or shadow them: a site group matching `/` would replace the
 * travel platform's home page with somebody's restaurant. Host has to be
 * decided before route matching, which is what a global middleware is.
 *
 * It is registered globally rather than in the `web` group deliberately, so a
 * site render never pays for the session, the CSRF cookie, the locale and
 * currency middleware or the Inertia share — none of which a Blade page for a
 * Namibian workshop has any use for.
 *
 * ## What it costs when there are no sites
 *
 * One array lookup against a cached host map, which is empty until a site has a
 * host. The travel platform must not slow down because a second product exists
 * in the same application.
 */
class ResolveSiteHost
{
    public function __construct(private readonly SiteResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isPassthrough($request)) {
            return $next($request);
        }

        $site = $this->resolver->forHost($request->getHost());

        if ($site === null) {
            return $next($request);
        }

        // The enquiry form is the one thing that posts. Everything else on a
        // site host is a page, and a stray POST is answered with a 404 rather
        // than falling through to the travel platform's routes.
        if ($request->isMethod('POST')) {
            abort_unless(trim($request->path(), '/') === 'enquiry', 404);

            return app(SiteEnquiryController::class)($request, $site);
        }

        abort_unless($request->isMethodSafe(), 404);

        // Resolved here rather than injected, so that a travel-platform request
        // — every request, until customers exist — does not build the
        // controller and its booking services just to walk past them.
        return app(SiteController::class)->render($request, $site, $request->path());
    }

    /**
     * Paths that keep belonging to the platform even on a site's host.
     *
     * `/thumbs` is the one that matters: images are emitted root-relative so
     * they travel over the connection the browser has already opened, which on
     * a weak mobile link is worth more than any cache an absolute URL would
     * share between sites.
     */
    private function isPassthrough(Request $request): bool
    {
        /** @var array<int, string> $paths */
        $paths = (array) config('sites.passthrough_paths', []);

        return $paths !== [] && $request->is(...$paths);
    }
}
