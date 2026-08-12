<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\NativeApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class AppleLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_apple_is_an_accepted_provider(): void
    {
        $this->mockAppleDriver(new SocialiteUser)->shouldNotReceive('user');

        $this->get(route('auth.social.redirect', 'apple'))->assertRedirect();
    }

    public function test_an_unknown_provider_is_still_rejected(): void
    {
        $this->get('/auth/microsoft/redirect')->assertNotFound();
    }

    /**
     * Apple posts the callback (response_mode=form_post), so the GET-only route
     * the other providers use would answer 405.
     */
    public function test_the_callback_accepts_a_post(): void
    {
        $this->mockAppleDriver($this->appleUser('apple-sub-1', 'traveler@example.com', 'Till Merle'));

        $this->post('/auth/apple/callback')->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'traveler@example.com',
            'provider' => 'apple',
            'provider_id' => 'apple-sub-1',
        ]);
    }

    public function test_the_post_callback_needs_no_csrf_token(): void
    {
        $this->mockAppleDriver($this->appleUser('apple-sub-2', 'other@example.com', 'Other'));

        $this->withMiddleware()
            ->post('/auth/apple/callback')
            ->assertRedirect(route('dashboard'));
    }

    /**
     * Apple sends name and email on the first authorization only. A returning
     * user is found on the stable sub, so the missing email costs nothing.
     */
    public function test_a_returning_user_is_matched_on_the_subject_alone(): void
    {
        $user = User::factory()->create([
            'provider' => 'apple',
            'provider_id' => 'apple-sub-3',
        ]);

        $this->mockAppleDriver($this->appleUser('apple-sub-3', null, null));

        $this->post('/auth/apple/callback')->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
    }

    /**
     * Same payload, but nobody to match it to — and users.email is NOT NULL, so
     * creating an account is impossible rather than merely awkward.
     */
    public function test_an_unknown_user_without_an_email_is_sent_back_to_the_login(): void
    {
        $this->mockAppleDriver($this->appleUser('apple-sub-4', null, null));

        $this->post('/auth/apple/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_an_existing_account_with_the_same_email_is_linked_not_duplicated(): void
    {
        $user = User::factory()->create(['email' => 'known@example.com']);

        $this->mockAppleDriver($this->appleUser('apple-sub-5', 'known@example.com', 'Known'));

        $this->post('/auth/apple/callback')->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertSame('apple', $user->refresh()->provider);
    }

    /**
     * The login page must not offer a provider we hold no credentials for —
     * the button would go straight to the provider's own error page.
     */
    public function test_only_configured_providers_are_offered(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.facebook.client_id' => null,
            'services.apple.client_id' => 'com.namibway.web',
        ]);

        $this->get(route('login'))->assertInertia(
            fn (AssertableInertia $page) => $page->where('socialProviders', ['google', 'apple']),
        );
    }

    /**
     * An OAuth redirect inside the shells' WebView is handed to the system
     * browser, so the traveller would finish signing in outside the app.
     */
    public function test_the_native_shells_are_offered_no_social_login(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.apple.client_id' => 'com.namibway.web',
        ]);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 '.NativeApp::USER_AGENT_MARKER)
            ->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('socialProviders', []));
    }

    public function test_a_browser_that_merely_mentions_apple_is_not_the_app(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.facebook.client_id' => null,
            'services.apple.client_id' => 'com.namibway.web',
        ]);

        // Every Safari and Chrome user agent on earth contains "AppleWebKit".
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Safari/537.36')
            ->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('socialProviders', ['google', 'apple']));
    }

    public function test_no_providers_at_all_means_no_block(): void
    {
        config([
            'services.google.client_id' => null,
            'services.facebook.client_id' => null,
            'services.apple.client_id' => null,
        ]);

        $this->get(route('login'))->assertInertia(
            fn (AssertableInertia $page) => $page->where('socialProviders', []),
        );
    }

    private function appleUser(string $id, ?string $email, ?string $name): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->id = $id;
        $user->email = $email;
        $user->name = $name;

        return $user;
    }

    /**
     * @return AbstractProvider&Mockery\MockInterface
     */
    private function mockAppleDriver(SocialiteUser $user): AbstractProvider
    {
        /** @var AbstractProvider&Mockery\MockInterface $driver */
        $driver = Mockery::mock(AbstractProvider::class);
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($user);
        $driver->shouldReceive('redirect')->andReturn(redirect('https://appleid.apple.com/auth/authorize'));

        Socialite::shouldReceive('driver')->with('apple')->andReturn($driver);

        return $driver;
    }
}
