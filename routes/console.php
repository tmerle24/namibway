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
Schedule::command('namibway:backfill-listing-coordinates')->dailyAt('01:30')->onOneServer()->withoutOverlapping();

// withoutOverlapping isn't used elsewhere in this file, but two concurrent
// POP3 sessions against the same mailbox risk double-processing a message.
Schedule::command('namibway:fetch-partner-emails')->everyTwoMinutes()->onOneServer()->withoutOverlapping();
