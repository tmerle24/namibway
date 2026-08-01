<?php

namespace Tests\Unit\Services\Currency;

use App\Models\User;
use App\Services\Currency\CurrencyLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class CurrencyLocatorTest extends TestCase
{
    use RefreshDatabase;

    private function request(array $server = []): Request
    {
        return Request::create('/', 'GET', server: $server);
    }

    public function test_guesses_nad_for_a_namibian_region_code()
    {
        App::setLocale('en');

        $request = $this->request(['HTTP_ACCEPT_LANGUAGE' => 'en-NA,en;q=0.9']);

        $this->assertSame('NAD', (new CurrencyLocator)->resolve($request));
    }

    public function test_guesses_eur_for_a_european_region_code()
    {
        App::setLocale('en');

        $request = $this->request(['HTTP_ACCEPT_LANGUAGE' => 'en-DE,en;q=0.9']);

        $this->assertSame('EUR', (new CurrencyLocator)->resolve($request));
    }

    public function test_guesses_eur_for_a_european_language_without_a_region_code()
    {
        App::setLocale('de');

        $request = $this->request(['HTTP_ACCEPT_LANGUAGE' => 'de']);

        $this->assertSame('EUR', (new CurrencyLocator)->resolve($request));
    }

    public function test_falls_back_to_usd_when_nothing_matches()
    {
        App::setLocale('en');

        $request = $this->request(['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9']);

        $this->assertSame('USD', (new CurrencyLocator)->resolve($request));
    }

    public function test_currency_cookie_wins_over_the_guess()
    {
        App::setLocale('en');

        $request = $this->request(['HTTP_ACCEPT_LANGUAGE' => 'en-NA']);
        $request->cookies->set('currency', 'GBP');

        $this->assertSame('GBP', (new CurrencyLocator)->resolve($request));
    }

    public function test_authenticated_users_preference_wins_over_the_cookie_and_guess()
    {
        $user = User::factory()->create(['preferred_currency' => 'EUR']);

        $request = $this->request(['HTTP_ACCEPT_LANGUAGE' => 'en-NA']);
        $request->cookies->set('currency', 'GBP');
        $request->setUserResolver(fn () => $user);

        $this->assertSame('EUR', (new CurrencyLocator)->resolve($request));
    }
}
