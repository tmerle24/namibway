<?php

use App\Http\Controllers\Partner\DemoSignInController;
use App\Http\Controllers\PartnerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Partner routes outside the Filament panel
|--------------------------------------------------------------------------
|
| These stay on whatever host serves the app, deliberately, even when the
| panel itself has moved to config('booking.panel_domain'). A URL signature
| covers the host, so an email sent last month with a confirm link in it must
| keep resolving on the host it was signed for — forwarding it elsewhere would
| invalidate the very signature that authorises it.
|
*/

Route::get('/partner/inquiries/{inquiry}/confirm', [PartnerController::class, 'confirm'])
    ->name('partner.inquiries.confirm')
    ->middleware('signed');

Route::get('/partner/inquiries/{inquiry}/cancel', [PartnerController::class, 'cancel'])
    ->name('partner.inquiries.cancel')
    ->middleware('signed');

/*
| The demo sign-in link is the exception: it is generated at the moment it is
| handed over, never stored, and it ends in the panel. Registering it on the
| panel's own host means the session cookie is written there directly, so it
| works whether or not the two hosts share a cookie domain.
*/
$demoSignIn = Route::get('/partner/demo-sign-in/{user}', DemoSignInController::class)
    ->name('partner.demo.sign-in')
    ->middleware('signed');

if (filled(config('booking.panel_domain'))) {
    $demoSignIn->domain((string) config('booking.panel_domain'));
}
