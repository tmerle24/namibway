<?php

use App\Http\Controllers\KaiaController;
use Illuminate\Support\Facades\Route;

Route::post('kaia/message', [KaiaController::class, 'message'])
    ->middleware('throttle:20,1')
    ->name('kaia.message');

Route::post('kaia/regenerate', [KaiaController::class, 'regenerate'])
    ->middleware('throttle:10,1')
    ->name('kaia.regenerate');

Route::get('kaia/regions', [KaiaController::class, 'regions'])
    ->middleware('throttle:30,1')
    ->name('kaia.regions');

Route::get('kaia/cities', [KaiaController::class, 'cities'])
    ->middleware('throttle:30,1')
    ->name('kaia.cities');

Route::get('kaia/region-coords', [KaiaController::class, 'regionCoords'])
    ->middleware('throttle:30,1')
    ->name('kaia.region-coords');

Route::post('kaia/plans', [KaiaController::class, 'savePlan'])
    ->middleware('throttle:20,1')
    ->name('kaia.plans.save');

Route::get('kaia/plans/{token}', [KaiaController::class, 'loadPlan'])
    ->middleware('throttle:60,1')
    ->name('kaia.plans.load');

Route::patch('kaia/plans/{token}', [KaiaController::class, 'updatePlan'])
    ->middleware('throttle:60,1')
    ->name('kaia.plans.update');

// "Save to my account" — the account line, enforced here rather than in the
// frontend. Creating and editing a plan stay open (token only); attaching one
// to an account does not.
Route::post('kaia/plans/{token}/claim', [KaiaController::class, 'claimPlan'])
    ->middleware(['auth', 'throttle:20,1'])
    ->name('kaia.plans.claim');
