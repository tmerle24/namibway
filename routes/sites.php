<?php

use App\Http\Controllers\Sites\SiteController;
use App\Http\Controllers\Sites\SiteEnquiryController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
| Customer websites, reached on the application's own host.
|
| A site's real address is its own host, resolved before routing by
| App\Http\Middleware\ResolveSiteHost. This is the back door: how a draft is
| reviewed before its DNS exists, how a site is looked at when no wildcard
| domain is configured at all — local development, CI — and how the renderer is
| exercised by tests.
|
| Registered in its own middleware group, which is deliberately empty. These
| pages want no session, no CSRF cookie, no locale negotiation and no Inertia
| share; the whole point is that they are cheap.
*/

$prefix = trim((string) config('sites.path_prefix', '_sites'), '/');

Route::get($prefix.'/{slug}/{page?}', [SiteController::class, 'path'])
    ->where('slug', '[a-z0-9-]+')
    ->where('page', '[a-z0-9-]+')
    ->name('sites.preview');

// The enquiry form posts here, from a site's own host as much as from the path
// fallback. Throttled rather than tokened: these pages carry no session, and an
// unauthenticated enquiry is not an action worth a cookie on every reader. See
// SiteEnquiryController.
// SubstituteBindings is named explicitly because the `sites` group is empty on
// purpose; without it {site:slug} arrives as a string and the controller is
// handed a blank model.
Route::post($prefix.'/{site:slug}/enquiry', SiteEnquiryController::class)
    ->middleware(['throttle:10,1', SubstituteBindings::class])
    ->name('sites.enquiry');
