<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CurrencyController extends Controller
{
    /**
     * Switch the visitor's display currency: sets the cookie for guests,
     * and persists the preference to the profile when logged in.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'currency' => ['required', 'string', Rule::in(config('currencies.supported'))],
        ]);

        if ($request->user()) {
            $request->user()->update(['preferred_currency' => $validated['currency']]);
        }

        return back()->withCookie(
            cookie('currency', $validated['currency'], 60 * 24 * 365)
        );
    }
}
