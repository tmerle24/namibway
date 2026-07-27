<?php

use App\Http\Controllers\ClaimController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ListingController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('listings/{listing:slug}', [ListingController::class, 'show'])->name('listings.show');
Route::post('listings/{listing:slug}/inquiries', [ListingController::class, 'storeInquiry'])
    ->middleware('throttle:10,1')
    ->name('listings.inquiries.store');

Route::get('claim/{token}', [ClaimController::class, 'show'])->name('claim.show');
Route::post('claim/{token}', [ClaimController::class, 'store'])->middleware('auth')->name('claim.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/kaia.php';
