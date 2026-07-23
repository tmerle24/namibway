<?php

use App\Http\Controllers\KaiaController;
use Illuminate\Support\Facades\Route;

Route::post('kaia/message', [KaiaController::class, 'message'])
    ->middleware('throttle:20,1')
    ->name('kaia.message');

Route::get('kaia/regions', [KaiaController::class, 'regions'])
    ->middleware('throttle:30,1')
    ->name('kaia.regions');

Route::get('kaia/alternatives', [KaiaController::class, 'alternatives'])
    ->middleware('throttle:60,1')
    ->name('kaia.alternatives');
