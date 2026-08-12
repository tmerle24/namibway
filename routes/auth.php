<?php

use App\Http\Controllers\Auth\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->where('provider', 'google|facebook|apple')
    ->name('auth.social.redirect');

Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->where('provider', 'google|facebook|apple')
    ->name('auth.social.callback');

// Apple asks for the name and email scopes, which forces response_mode=form_post:
// the callback arrives as a cross-site POST, not a GET. Same controller action —
// only the verb differs. CSRF is excepted for it in bootstrap/app.php, because
// Apple cannot send a token of ours.
Route::post('auth/apple/callback', [SocialAuthController::class, 'callback'])
    ->defaults('provider', 'apple')
    ->name('auth.social.callback.apple');
