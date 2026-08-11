<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveSiteHost;
use App\Http\Middleware\SetCurrency;
use App\Http\Middleware\SetLocale;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Customer websites, in their own middleware group — see
            // routes/sites.php and App\Http\Middleware\ResolveSiteHost.
            Route::middleware('sites')->group(__DIR__.'/../routes/sites.php');
        },
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('partners:sync-content')->dailyAt('03:00');

        $schedule->command('currency:refresh-rates')->dailyAt('04:00');

        // Scrapes namibiayp.com every 6h until all pages are exhausted.
        // Idempotent: already-scraped companies are skipped automatically.
        // Listings with photos are auto-published; without photos stay unpublished.
        $schedule->command('listings:scrape-namibiayp --pages=20')
            ->everySixHours()
            ->withoutOverlapping()
            ->runInBackground();

        // Runs inline (no queue worker required) — crawls a small batch every 5
        // minutes so listings cycle through steadily without hammering any one
        // partner website (see CrawlListingWebsiteJob's per-host cooldown).
        $schedule->command('namibway:scrape-websites --limit=10')->everyFiveMinutes()->withoutOverlapping();

        // Paused 2026-08-04: Google Places API costs were running too high. Re-enable
        // (with a lower --limit) once the cost driver is understood.
        // $schedule->command('namibway:fetch-google-photos --limit=100')->everyFifteenMinutes()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale', 'currency']);

        // Before route matching, because the travel platform's routes carry no
        // host constraint and would otherwise answer on a customer's domain.
        // Costs one cached array lookup while no site has a host, which is the
        // state of the world until the first customer signs.
        $middleware->prepend(ResolveSiteHost::class);

        // Deliberately empty. A customer website renders Blade and nothing
        // else: no session, no CSRF cookie, no locale or currency negotiation,
        // no Inertia share. That is most of how the page stays small enough to
        // open on a weak mobile connection.
        $middleware->group('sites', []);

        $middleware->web(append: [
            HandleAppearance::class,
            SetLocale::class,
            SetCurrency::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
