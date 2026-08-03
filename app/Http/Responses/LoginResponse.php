<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * Always land signed-in users on the dashboard, regardless of which page
     * they were on before hitting /login — Fortify's default `intended()`
     * behavior would otherwise send them back to wherever they came from.
     */
    public function toResponse($request)
    {
        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->to(route('dashboard'));
    }
}
