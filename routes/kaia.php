<?php

use App\Http\Controllers\KaiaController;
use Illuminate\Support\Facades\Route;

Route::post('kaia/message', [KaiaController::class, 'message'])
    ->middleware('throttle:20,1')
    ->name('kaia.message');
