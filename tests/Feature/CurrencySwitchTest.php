<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencySwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_switching_currency_sets_a_cookie()
    {
        $response = $this->post('/currency', ['currency' => 'EUR']);

        $response->assertRedirect();
        $response->assertCookie('currency', 'EUR', encrypted: false);
    }

    public function test_authenticated_user_switching_currency_persists_to_profile()
    {
        $user = User::factory()->create(['preferred_currency' => null]);

        $response = $this->actingAs($user)->post('/currency', ['currency' => 'EUR']);

        $response->assertRedirect();
        $response->assertCookie('currency', 'EUR', encrypted: false);
        $this->assertSame('EUR', $user->fresh()->preferred_currency);
    }

    public function test_rejects_unsupported_currencies()
    {
        $response = $this->post('/currency', ['currency' => 'XYZ']);

        $response->assertSessionHasErrors('currency');
    }
}
