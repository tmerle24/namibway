<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('partners:sync-content')->dailyAt('03:00');

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
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale']);

        $middleware->web(append: [
            HandleAppearance::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
