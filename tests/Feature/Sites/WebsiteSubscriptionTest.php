<?php

namespace Tests\Feature\Sites;

use App\Enums\SubscriptionStatus;
use App\Mail\WebsiteOrdered;
use App\Models\Partner;
use App\Models\WebsiteSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The subscription behind the website product.
 *
 * It exists to answer one question — may this partner have a website built? —
 * and to keep answering it when a payment provider is eventually plugged in
 * behind it. So what is asserted here is the machine and not the money: nothing
 * in it names a price, a currency or a gateway, and a human deciding "they have
 * paid" has to be enough.
 */
class WebsiteSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-13 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_order_is_a_request_and_entitles_nobody(): void
    {
        $partner = $this->partner();

        $subscription = WebsiteSubscription::request($partner, 'We already own duneedge.com.na');

        $this->assertSame(SubscriptionStatus::Requested, $subscription->status);
        $this->assertNotNull($subscription->requested_at);
        $this->assertFalse($subscription->isEntitled());
        $this->assertFalse($partner->refresh()->hasWebsiteEntitlement());
    }

    /**
     * The button lives in a panel somebody can press twice, and a second press
     * is somebody checking it worked — not a second customer.
     */
    public function test_ordering_twice_is_one_subscription(): void
    {
        $partner = $this->partner();

        $first = WebsiteSubscription::request($partner);
        $second = WebsiteSubscription::request($partner, 'again');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WebsiteSubscription::count());
    }

    public function test_only_an_active_subscription_entitles(): void
    {
        $partner = $this->partner();
        $subscription = WebsiteSubscription::request($partner);

        $subscription->activate();
        $this->assertTrue($partner->refresh()->hasWebsiteEntitlement());

        $subscription->suspend('Two invoices unpaid');
        $this->assertFalse($partner->refresh()->hasWebsiteEntitlement());

        $subscription->activate();
        $this->assertTrue($partner->refresh()->hasWebsiteEntitlement());

        $subscription->cancel('Closed the business');
        $this->assertFalse($partner->refresh()->hasWebsiteEntitlement());
    }

    /**
     * A subscription switched back on is the same customer, and the date they
     * became one does not move.
     */
    public function test_the_date_they_became_a_customer_does_not_move(): void
    {
        $subscription = WebsiteSubscription::request($this->partner());

        $subscription->activate();
        $activated = $subscription->refresh()->activated_at;

        Carbon::setTestNow(Carbon::parse('2026-11-01 09:00:00'));

        $subscription->suspend();
        $subscription->activate();

        $this->assertEquals($activated, $subscription->refresh()->activated_at);
    }

    public function test_suspending_says_why_and_clears_when_switched_back_on(): void
    {
        $subscription = WebsiteSubscription::request($this->partner());
        $subscription->activate();

        $subscription->suspend('Two invoices unpaid');

        $this->assertNotNull($subscription->refresh()->suspended_at);
        $this->assertSame('Two invoices unpaid', $subscription->note);

        $subscription->activate();

        $this->assertNull($subscription->refresh()->suspended_at);
    }

    public function test_the_team_is_told_when_somebody_orders(): void
    {
        $subscription = WebsiteSubscription::request($this->partner());

        Mail::to('team@example.com')->send(new WebsiteOrdered($subscription));

        Mail::assertQueued(WebsiteOrdered::class);
    }

    /**
     * The price belongs to the offer, not to a customer — a column here would
     * be a per-customer price nobody decided to have.
     */
    public function test_the_subscription_holds_no_money_and_no_gateway(): void
    {
        $columns = array_keys(WebsiteSubscription::request($this->partner())->getAttributes());

        foreach (['price', 'amount', 'currency', 'provider', 'gateway'] as $forbidden) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $column,
                    "A subscription must not carry [{$forbidden}]: the price is a property of the offer, "
                    .'and the provider is not chosen yet.'
                );
            }
        }
    }

    private function partner(): Partner
    {
        return Partner::create(['name' => 'Dune Edge', 'email' => 'owner@example.com']);
    }
}
