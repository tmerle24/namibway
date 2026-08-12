<?php

use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\PaymentCallbackController;
use App\Http\Middleware\EnsureApiClientActive;
use Illuminate\Support\Facades\Route;

/*
 * Where a payment gateway posts what happened.
 *
 * In the API group rather than the web one, because a gateway has no session
 * and no CSRF token — putting it in `web` would mean exempting it by hand,
 * which is the same thing said less clearly. Outside the /v1 prefix and its
 * Sanctum guard: a webhook authenticates by signature, which the provider
 * checks, and asking it for a bearer token would be asking for something no
 * gateway sends.
 *
 * The provider name is resolved through the factory; an unknown one is a 404
 * rather than an error, because it is somebody probing.
 */
Route::post('payments/callback/{provider}', PaymentCallbackController::class)
    ->middleware('throttle:120,1')
    ->name('payments.callback');

Route::prefix('v1')
    ->middleware(['auth:sanctum', EnsureApiClientActive::class, 'throttle:api'])
    ->group(function () {
        Route::get('listings', [ListingController::class, 'index'])->name('api.listings.index');
        Route::get('listings/{listing:slug}', [ListingController::class, 'show'])->name('api.listings.show');
        Route::get('listings/{listing:slug}/availability', [ListingController::class, 'availability'])->name('api.listings.availability');
    });
