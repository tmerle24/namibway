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

Schedule::command('listings:nightly-enrich')->dailyAt('01:00')->onOneServer();

// withoutOverlapping isn't used elsewhere in this file, but two concurrent
// POP3 sessions against the same mailbox risk double-processing a message.
Schedule::command('namibway:fetch-partner-emails')->everyTwoMinutes()->onOneServer()->withoutOverlapping();
