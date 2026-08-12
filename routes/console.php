<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// No --only-db: also picks up .env per config/backup.php (see restore.sh).
Schedule::command('backup:run')->dailyAt('02:00')->onOneServer();
Schedule::command('backup:clean')->dailyAt('03:00')->onOneServer();
Schedule::command('backup:monitor')->dailyAt('04:00')->onOneServer();

// Paused 2026-08-04: Claude API costs from the enrichment pipeline were running too
// high. Re-enable (with a smaller nightly_batch_size) once understood.
// Schedule::command('listings:nightly-enrich')->dailyAt('01:00')->onOneServer();

// One-off backlog of ~7000 listings with an address but no lat/lng (older data
// predating ListingController::show()'s on-view geocode fallback). Cheap once the
// backlog clears — the underlying query is a fast no-op — so it's fine to leave
// running nightly as a safety net for new listings that slip through with an
// address but no coordinates.
// A customer changes an A record once and then waits, so what matters is
// noticing within minutes rather than the next morning. The command only
// looks DNS up and writes down what it saw — issuing the certificate and
// touching nginx happen outside the application entirely. See
// DEPLOYMENT.md, "Custom domains".
Schedule::command('sites:check-domains')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('namibway:backfill-listing-coordinates')->dailyAt('01:30')->onOneServer()->withoutOverlapping();

// withoutOverlapping isn't used elsewhere in this file, but two concurrent
// POP3 sessions against the same mailbox risk double-processing a message.
Schedule::command('namibway:fetch-partner-emails')->everyTwoMinutes()->onOneServer()->withoutOverlapping();

// Drains the website-crawl backlog in small, steady sips: listings that have a
// website but no recent crawl, oldest (and never-crawled) first. Small batch on
// a short interval rather than a big nightly run — a listing whose website was
// just filled in (by an import or a partner edit, see ListingObserver) becomes
// useful within minutes instead of the next day. This path costs no AI budget:
// it reads og:/meta tags and photos from the owner's own site, nothing else.
Schedule::command('namibway:scrape-websites --limit=10')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
