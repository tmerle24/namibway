<?php

use App\Http\Controllers\Api\ListingController;
use App\Http\Middleware\EnsureApiClientActive;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['auth:sanctum', EnsureApiClientActive::class, 'throttle:api'])
    ->group(function () {
        Route::get('listings', [ListingController::class, 'index'])->name('api.listings.index');
        Route::get('listings/{listing:slug}', [ListingController::class, 'show'])->name('api.listings.show');
        Route::get('listings/{listing:slug}/availability', [ListingController::class, 'availability'])->name('api.listings.availability');
    });
