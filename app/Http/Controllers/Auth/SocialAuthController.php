<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\Provider as ProviderContract;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class SocialAuthController extends Controller
{
    /** @var list<string> */
    public const PROVIDERS = ['google', 'facebook', 'apple'];

    /**
     * The providers that are actually usable, so the login page can offer only
     * those. A button for a provider with no credentials is a dead end — and
     * before Apple was added, that is what the Facebook button already was.
     *
     * @return list<string>
     */
    public static function configured(): array
    {
        return array_values(array_filter(
            self::PROVIDERS,
            fn (string $provider): bool => filled(config("services.{$provider}.client_id")),
        ));
    }

    public function redirect(string $provider): SymfonyRedirectResponse
    {
        $this->ensureProviderIsSupported($provider);

        return $this->driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->ensureProviderIsSupported($provider);

        $socialUser = $this->driver($provider)->user();

        $user = User::query()->where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if (! $user && $socialUser->getEmail()) {
            $user = User::query()->where('email', $socialUser->getEmail())->first();
        }

        if ($user) {
            $user->forceFill([
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        } else {
            // Apple releases the name and email only on the very first
            // authorization; every later sign-in carries the stable `sub`
            // alone. That is fine for someone we already know, but there is
            // nothing to create an account from — and users.email is NOT NULL.
            // Happens when a user revokes access and comes back, so it is a
            // real path, not a theoretical one.
            if (! $socialUser->getEmail()) {
                return redirect()->route('login')->withErrors([
                    'email' => __('We could not read an email address from your :provider account. Please sign in with your email address, or remove NamibWay under your Apple Account settings and try again.', [
                        'provider' => ucfirst($provider),
                    ]),
                ]);
            }

            $user = User::create([
                'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'NamibWay Traveler',
                'email' => $socialUser->getEmail(),
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'email_verified_at' => now(),
            ]);
        }

        Auth::login($user, remember: true);

        return redirect()->to(route('dashboard'));
    }

    private function driver(string $provider): ProviderContract
    {
        $driver = Socialite::driver($provider);

        // Apple's callback is a cross-site POST, and the session cookie is
        // SameSite=lax — so the browser withholds it and Socialite's state
        // check would fail every time. The flow stays safe without it: the
        // code is exchanged over TLS server-side and the identity is read
        // from a JWT verified against Apple's published keys.
        if ($provider === 'apple' && $driver instanceof AbstractProvider) {
            $driver->stateless();
        }

        return $driver;
    }

    private function ensureProviderIsSupported(string $provider): void
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), Response::HTTP_NOT_FOUND);
    }
}
