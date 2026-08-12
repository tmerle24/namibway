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
| Confirm and ask for the deposit, from the same email.
|
| Two routes on one URI rather than a second signed link: a signature covers
| the address, not the method, so the page the GET renders can post its form
| straight back to the address it was reached at. The POST is what acts — the
| GET only offers the optional message, because a partner who opens a link to
| read it has not yet decided anything.
*/
Route::get('/partner/inquiries/{inquiry}/confirm-with-payment', [PartnerController::class, 'showConfirmWithPayment'])
    ->name('partner.inquiries.confirm-with-payment')
    ->middleware('signed');

Route::post('/partner/inquiries/{inquiry}/confirm-with-payment', [PartnerController::class, 'confirmWithPayment'])
    ->name('partner.inquiries.confirm-with-payment.send')
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

    /*
    | Everything else under /partner on the main site is the panel, which has
    | moved. Forwarding it keeps every bookmark and every link already sent in
    | an email working.
    |
    | Constrained to the app's own host, or it would also match on the booking
    | host and redirect the panel to itself for ever. And the two signed routes
    | above are excluded by the pattern rather than by ordering: a signature
    | covers the host, so forwarding one would invalidate it, and relying on
    | registration order for that would be a trap for whoever adds the third
    | signed route.
    */
    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    if (is_string($appHost) && $appHost !== '') {
        Route::domain($appHost)
            ->get('/partner/{path?}', fn (?string $path = null) => redirect()->away(
                'https://'.config('booking.panel_domain').'/partner'.($path === null ? '' : '/'.$path)
            ))
            ->where('path', '(?!inquiries|demo-sign-in)[A-Za-z0-9\-_/]*')
            ->name('partner.panel.moved');
    }
}
