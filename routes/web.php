<?php

use App\Http\Controllers\ClaimController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\SavedPlanController;
use App\Http\Controllers\TripController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('listings/{listing:slug}', [ListingController::class, 'show'])->name('listings.show');

Route::post('trip/save', [SavedPlanController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('trip.save');

Route::get('trip/{token}', [SavedPlanController::class, 'show'])
    ->name('trip.show');

Route::get('trip/{token}/pdf', [SavedPlanController::class, 'pdf'])
    ->middleware('throttle:10,1')
    ->name('trip.pdf');
Route::post('listings/{listing:slug}/inquiries', [ListingController::class, 'storeInquiry'])
    ->middleware('throttle:10,1')
    ->name('listings.inquiries.store');

Route::post('trips', [TripController::class, 'store'])->middleware('throttle:5,1')->name('trips.store');
Route::get('trips/{trip}/inquiries', [TripController::class, 'inquiries'])->middleware('throttle:30,1')->name('trips.inquiries');

Route::get('claim/{token}', [ClaimController::class, 'show'])->name('claim.show');
Route::post('claim/{token}', [ClaimController::class, 'store'])->middleware('auth')->name('claim.store');

// Store intended URL in session so Fortify redirects back after login
Route::get('login/start', function (Request $request) {
    $url = $request->query('redirect', '');

    if (is_string($url) && str_starts_with($url, '/')) {
        $request->session()->put('url.intended', url($url));
    }

    return redirect()->route('login');
})->middleware('guest')->name('login.start');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/kaia.php';
require __DIR__.'/partner.php';
