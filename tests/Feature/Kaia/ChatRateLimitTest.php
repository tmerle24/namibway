<?php

namespace Tests\Feature\Kaia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What Kaia's chat is rationed by.
 *
 * The endpoint costs money per request, so it has to be rationed — but it was
 * rationed by IP address, and an address is not a traveler. A phone here is
 * behind a mobile carrier's NAT, a lodge is one line for all its guests, and
 * an operator's office is one line for the whole team; every one of those is
 * a single bucket that strangers share. What that looked like from the other
 * end was somebody being told, on the second message they had ever sent, that
 * they had sent "a lot of messages in a short time" — with a Retry link that
 * could only produce the same refusal again.
 *
 * So two things are pinned here: a conversation is charged for its own
 * messages and nobody else's, and a refusal says when it stops being one.
 */
class ChatRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key' => 'test-key',
            // Small enough to spend in a test. The limiter reads these per
            // request, so what is exercised is the real closure, not a copy
            // of it with different numbers.
            'kaia.rate_limit.per_conversation' => 3,
            'kaia.rate_limit.per_address' => 5,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'tool_use',
                    'name' => 'reply_to_traveler',
                    'input' => ['text' => 'How many days do you have?'],
                ]],
            ], 200),
        ]);
    }

    /**
     * The session cookie is the conversation, and handing the same one to two
     * requests is the only way to have two of them in a test: the framework
     * mints a fresh id for every request that arrives without one. `withCookie`
     * encrypts it the way a browser's would be, so EncryptCookies hands the
     * session back the id below rather than a blob it cannot read — and
     * `withCredentials` is what makes a JSON request send cookies at all.
     */
    private function chat(string $conversation): TestResponse
    {
        return $this
            ->withCredentials()
            ->withCookie(config('session.cookie'), $conversation)
            ->postJson('/kaia/message', [
                'history' => [['role' => 'user', 'text' => 'I want to plan a trip']],
            ]);
    }

    private function newConversation(): string
    {
        return Str::random(40);
    }

    public function test_a_conversation_is_charged_for_its_own_messages_only(): void
    {
        $mine = $this->newConversation();

        for ($i = 0; $i < 3; $i++) {
            $this->chat($mine)->assertOk();
        }

        $this->chat($mine)->assertStatus(429);

        // Same address, same minute, a different chat — the case that used to
        // greet a second traveler with somebody else's rate limit.
        $this->chat($this->newConversation())->assertOk();
    }

    public function test_a_refusal_says_when_to_come_back(): void
    {
        $mine = $this->newConversation();

        for ($i = 0; $i < 3; $i++) {
            $this->chat($mine)->assertOk();
        }

        $response = $this->chat($mine)->assertStatus(429);

        // The chat waits this out and resumes on its own. Without it there is
        // nothing to count down, and the only thing left to offer the
        // traveler is the button that fails again.
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_the_address_is_still_a_backstop(): void
    {
        // A session cookie is free to mint, so the per-conversation limit
        // stops nobody who does not want to be stopped. Five requests from
        // five conversations still exhaust one address.
        for ($i = 0; $i < 5; $i++) {
            $this->chat($this->newConversation())->assertOk();
        }

        $this->chat($this->newConversation())->assertStatus(429);
    }

    public function test_regenerating_a_plan_draws_on_the_same_budget(): void
    {
        // Both endpoints call Claude, so both spend the same minute of it.
        // Rationing them separately would let one traveler pay twice over.
        $mine = $this->newConversation();

        for ($i = 0; $i < 3; $i++) {
            $this->chat($mine)->assertOk();
        }

        $this->withCredentials()
            ->withCookie(config('session.cookie'), $mine)
            ->postJson('/kaia/regenerate', [
                'nights' => 5,
                'travel_period' => 'September',
                'budget_tier' => 'mid-range',
                'adults' => 2,
                'children_under_13' => 0,
                'vehicle_type' => 'car',
            ])
            ->assertStatus(429);
    }
}
