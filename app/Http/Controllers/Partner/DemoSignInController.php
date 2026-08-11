<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Signs a demo account in from the link `booking:demo-tenant` prints, so a
 * laptop can be handed across a table without anybody typing a password or
 * being sent through a reset flow.
 *
 * Two things keep this from being a back door into the panel. The URL is
 * signed and expires, so it cannot be guessed or kept; and the account must
 * belong to a demo tenant, so even a validly signed link — one this command
 * printed, one found in an old chat thread — can only ever open a sandbox.
 * The second check is the one that matters: signatures are about who made the
 * link, and this is about which accounts may be opened this way at all.
 */
class DemoSignInController extends Controller
{
    public function __invoke(User $user): RedirectResponse
    {
        $user->loadMissing('partner');

        abort_unless(
            $user->partner?->is_demo === true,
            403,
            'This sign-in link only works for demo accounts.'
        );

        // login() migrates the session itself, so there is no fixation window
        // to close afterwards.
        Auth::login($user);

        return redirect()->to(Filament::getPanel('partner')->getUrl());
    }
}
