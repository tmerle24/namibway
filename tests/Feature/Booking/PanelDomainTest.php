<?php

namespace Tests\Feature\Booking;

use App\Enums\InquiryStatus;
use App\Enums\ListingType;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The partner panel on its own host.
 *
 * Two things have to hold at once: the panel answers on
 * booking.namibway.com, and nothing already in use breaks — a bookmark, a
 * link in an email, a signed confirm URL sent last month.
 *
 * The signed links are the sharp edge. A URL signature covers the host, so
 * forwarding one to another host invalidates the very thing that authorises
 * it. They therefore stay on the main site, and the forwarding rule is
 * written to exclude them by pattern rather than by route ordering, which
 * would be a trap for whoever adds the third signed route.
 *
 * The host is switched on through the environment before the application is
 * created, because the panel binds its routes while booting — which is also
 * why an unset value costs local development and CI nothing at all.
 */
class PanelDomainTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'booking.namibway.test';

    protected function setUp(): void
    {
        // Written to every place Laravel's environment repository reads from,
        // and before the application exists: the panel binds its routes while
        // its provider registers, so setting config afterwards is too late.
        // .env deliberately carries no BOOKING_PANEL_DOMAIN key at all, so
        // there is nothing to overwrite these.
        foreach (['BOOKING_PANEL_DOMAIN' => self::HOST, 'APP_URL' => 'http://localhost'] as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach (['BOOKING_PANEL_DOMAIN', 'APP_URL'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        parent::tearDown();
    }

    public function test_the_panel_answers_on_the_booking_host(): void
    {
        $user = $this->partnerUser();

        $this->actingAs($user)
            ->get('http://'.self::HOST.'/partner/calendar')
            ->assertOk();
    }

    public function test_a_signed_in_session_survives_a_real_sign_in_on_the_booking_host(): void
    {
        // A genuine HTTP sign-in on the subdomain, not an actingAs shortcut:
        // the session cookie has to be written on that host and then accepted
        // by the panel on the next request.
        $partner = Partner::create(['name' => 'Demo Lodge', 'is_demo' => true]);
        $user = User::factory()->create(['partner_id' => $partner->id]);
        $this->property($partner->id);

        $link = URL::temporarySignedRoute('partner.demo.sign-in', now()->addDay(), ['user' => $user->id]);

        $this->assertStringContainsString(self::HOST, $link);

        $this->get($link)->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $this->get('http://'.self::HOST.'/partner/calendar')->assertOk();
    }

    public function test_a_guest_is_sent_to_the_login_page_on_the_booking_host(): void
    {
        $this->get('http://'.self::HOST.'/partner/calendar')
            ->assertRedirect('http://'.self::HOST.'/partner/login');

        $this->get('http://'.self::HOST.'/partner/login')->assertOk();
    }

    public function test_the_booking_hosts_root_is_the_panel_and_not_the_travel_site(): void
    {
        $this->get('http://'.self::HOST)->assertRedirect('/partner');
    }

    public function test_the_old_partner_path_still_works_by_forwarding(): void
    {
        $this->assertSame('partner.panel.moved', $this->routeNameFor('http://localhost/partner/calendar'));

        $this->get('/partner')
            ->assertRedirect('https://'.self::HOST.'/partner');

        $this->get('/partner/calendar')
            ->assertRedirect('https://'.self::HOST.'/partner/calendar');

        $this->get('/partner/login')
            ->assertRedirect('https://'.self::HOST.'/partner/login');
    }

    public function test_a_signed_partner_link_is_not_forwarded_and_still_resolves(): void
    {
        $inquiry = $this->inquiry();

        $link = URL::signedRoute('partner.inquiries.confirm', ['inquiry' => $inquiry->id]);

        // Signed on the main host, and it must stay there: a redirect would
        // change the host the signature was computed over.
        $this->assertStringNotContainsString(self::HOST, $link);

        // Asserted at the router rather than through the controller: what
        // matters here is which route the URL reaches, not what that route
        // then decides to do with the inquiry.
        $this->assertSame('partner.inquiries.confirm', $this->routeNameFor($link));
    }

    public function test_the_demo_sign_in_route_is_not_forwarded_either(): void
    {
        $partner = Partner::create(['name' => 'Demo Lodge', 'is_demo' => true]);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        // On the main host it simply does not exist, rather than being
        // forwarded and losing its signature on the way.
        $this->get('/partner/demo-sign-in/'.$user->id)->assertNotFound();
    }

    /** Which route a URL actually reaches, host constraints included. */
    private function routeNameFor(string $url): ?string
    {
        return Route::getRoutes()->match(Request::create($url))->getName();
    }

    private function partnerUser(): User
    {
        $partner = Partner::create(['name' => 'Camp', 'email' => 'camp@example.com']);
        $this->property($partner->id);

        return User::factory()->create(['partner_id' => $partner->id]);
    }

    private function property(int $partnerId): Listing
    {
        return Listing::factory()->create([
            'partner_id' => $partnerId,
            'type' => ListingType::Accommodation,
            'name' => 'Camp',
        ]);
    }

    private function inquiry(): Inquiry
    {
        $partner = Partner::create(['name' => 'Real Lodge', 'email' => 'owner@example.com']);

        return Inquiry::create([
            'listing_id' => $this->property($partner->id)->id,
            'name' => 'A Traveller',
            'email' => 'traveller@example.com',
            'status' => InquiryStatus::OnRequest,
        ]);
    }
}
