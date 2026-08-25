<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `throttle:30,1` has to mean thirty requests a minute to *that* route.
 *
 * Laravel's own key for an un-prefixed numeric throttle is the domain and the
 * caller — nothing about the route — so every numerically throttled route in
 * the application shared one counter, and the strictest cap among them
 * governed all of them together. That is not a theoretical tidiness problem:
 * it is why Kaia stopped answering. Opening a trip plan spends region
 * coordinates, city lists, route stops, supply stops and an autosave per edit,
 * and twenty of those in a minute left the chat — `throttle:20,1` — refusing
 * every message with a 429 until the minute was out.
 *
 * App\Http\Middleware\ThrottleRequests puts the route back into the key. What
 * is pinned here is both halves of that: one route's traffic does not spend
 * another's, and each route still runs out on its own.
 */
class ThrottleScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_route_does_not_spend_another_routes_budget(): void
    {
        // Both of these are throttle:30,1. Spending the first one's minute
        // used to leave the second with nothing.
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/kaia/regions')->assertOk();
        }

        $this->getJson('/kaia/cities')->assertOk();
    }

    public function test_a_route_still_runs_out_of_its_own_budget(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/kaia/regions')->assertOk();
        }

        $this->getJson('/kaia/regions')->assertStatus(429);
    }
}
