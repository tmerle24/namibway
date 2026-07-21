<?php

use App\Http\Controllers\Auth\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->where('provider', 'google|facebook')
    ->name('auth.social.redirect');

Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->where('provider', 'google|facebook')
    ->name('auth.social.callback');
