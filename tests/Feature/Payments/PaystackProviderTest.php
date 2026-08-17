<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentPurpose;
use App\Enums\ReservationSource;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\Providers\PaymentProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Paystack, against a faked HTTP client — no network, no account.
 *
 * Worth stating again where a reader will find it: **Paystack does not support
 * Namibia as a merchant country**, so this is written against a South African
 * entity settling in ZAR. See PaystackProvider. Nothing here validates the
 * integration against a real account, and the first one that runs will turn up
 * surprises — the same caveat every connector in this codebase carries.
 *
 * What these tests actually pin down are the three things that would be wrong
 * silently: the amount is sent in minor units, the folio's NAD is charged as
 * ZAR at the peg with all three currency facts kept, and a webhook is believed
 * only when its signature checks out.
 */
class PaystackProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_pretend';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payments.default_provider', 'paystack');
        config()->set('payments.providers.paystack.secret_key', self::SECRET);
        config()->set('payments.providers.paystack.currencies', ['ZAR']);

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_nad_folio_is_charged_in_rand_at_the_peg_in_minor_units(): void
    {
        Http::fake([
            '*/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'access_code' => 'abc123',
                    'reference' => 'ref-from-paystack',
                ],
            ]),
        ]);

        $stay = $this->stay();
        $intent = app(PaymentGateway::class)->start($stay, PaymentPurpose::Deposit);

        $this->assertSame('NAD', $intent->currency);
        $this->assertSame('ZAR', $intent->charge_currency);
        $this->assertSame(1.0, $intent->exchange_rate);
        $this->assertSame('https://checkout.paystack.com/abc123', $intent->meta['redirect_url'] ?? null);
        $this->assertSame('ref-from-paystack', $intent->provider_reference);

        Http::assertSent(function ($request) use ($intent): bool {
            $body = $request->data();

            return str_contains($request->url(), '/transaction/initialize')
                // Minor units as an integer. A float here is rejected by
                // Paystack, and a rounding error is money.
                && $body['amount'] === (int) round($intent->charge_amount * 100)
                && $body['currency'] === 'ZAR'
                // Our reference, not theirs — it is what a callback is matched
                // on, and it exists before Paystack has seen the attempt.
                && $body['reference'] === $intent->reference
                && $body['email'] === 'guest@example.com';
        });
    }

    public function test_a_booking_with_no_guest_email_is_refused_before_a_link_is_made(): void
    {
        Http::fake();

        $stay = $this->stay(email: null);

        // Refused rather than sent with a placeholder: a made-up address means
        // the receipt goes nowhere and a dispute has no counterparty.
        $this->expectExceptionMessage('email address');

        app(PaymentGateway::class)->start($stay, PaymentPurpose::Deposit);
    }

    public function test_a_signed_webhook_settles_the_attempt_and_the_amount_comes_from_verify(): void
    {
        $stay = $this->stay();
        $intent = $this->startIntent($stay);

        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => $intent->reference,
                    'channel' => 'card',
                    // Deliberately a *different* amount from the folio's. The
                    // webhook and the verify response do not get to set what
                    // was paid — the intent does. A gateway body that could
                    // set an amount is a forged paid booking.
                    'amount' => 1,
                ],
            ]),
        ]);

        $payload = ['event' => 'charge.success', 'data' => ['reference' => $intent->reference]];

        $this->callWebhook($payload)->assertOk();

        $intent->refresh();
        $stay->refresh();

        $this->assertSame(PaymentIntentStatus::Succeeded, $intent->status);

        $payment = $stay->payments()->sole();
        $this->assertSame($intent->amount, $payment->amount);
        $this->assertSame('NAD', $payment->currency);
        $this->assertSame($intent->charge_amount, $payment->received_amount);
        $this->assertSame('ZAR', $payment->received_currency);
    }

    public function test_an_unsigned_webhook_changes_nothing(): void
    {
        $stay = $this->stay();
        $intent = $this->startIntent($stay);

        Http::fake(['*/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success']])]);

        $this->postJson(route('payments.callback', ['provider' => 'paystack']), [
            'event' => 'charge.success',
            'data' => ['reference' => $intent->reference],
        ])->assertOk();

        // 200 so the gateway keeps delivering, and nothing happened.
        $this->assertSame(PaymentIntentStatus::Pending, $intent->fresh()?->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_webhook_signed_with_the_wrong_secret_changes_nothing(): void
    {
        $stay = $this->stay();
        $intent = $this->startIntent($stay);

        Http::fake(['*/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success']])]);

        $payload = ['event' => 'charge.success', 'data' => ['reference' => $intent->reference]];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        $this->call(
            'POST',
            route('payments.callback', ['provider' => 'paystack']),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_somebody_else'),
            ],
            $body,
        )->assertOk();

        $this->assertSame(PaymentIntentStatus::Pending, $intent->fresh()?->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_an_event_we_do_not_handle_is_accepted_and_ignored(): void
    {
        Http::fake();

        // A gateway that gets errors stops delivering, so an uninteresting
        // event has to be a 200.
        $this->callWebhook(['event' => 'customeridentification.success', 'data' => ['reference' => 'x']])
            ->assertOk();

        $this->assertSame(0, Payment::count());
    }

    public function test_an_unknown_verify_status_leaves_the_attempt_open(): void
    {
        $stay = $this->stay();
        $intent = $this->startIntent($stay);

        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'ongoing'],
            ]),
        ]);

        app(PaymentGateway::class)->settle($intent);

        // Not a failure: a payment that settles a minute later has to have
        // somewhere to land.
        $this->assertSame(PaymentIntentStatus::Pending, $intent->fresh()?->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_the_factory_refuses_paystack_without_a_secret_key(): void
    {
        config()->set('payments.providers.paystack.secret_key', null);

        $this->expectExceptionMessage('PAYSTACK_SECRET_KEY');

        app(PaymentProviderFactory::class)->make('paystack');
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    private function startIntent(Reservation $stay): PaymentIntent
    {
        Http::fake([
            '*/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'reference' => null,
                ],
            ]),
        ]);

        return app(PaymentGateway::class)->start($stay, PaymentPurpose::Deposit);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callWebhook(array $payload): TestResponse
    {
        // Signed over the *raw* body, because that is what Paystack signs and
        // what the provider verifies. Re-encoding JSON changes bytes, which is
        // the classic way a webhook check silently never matches.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        return $this->call(
            'POST',
            route('payments.callback', ['provider' => 'paystack']),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET),
            ],
            $body,
        );
    }

    private function stay(?string $email = 'guest@example.com'): Reservation
    {
        $partner = Partner::create(['name' => 'Etosha Camp', 'email' => fake()->unique()->safeEmail()]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'name' => 'Etosha Camp',
            'is_published' => true,
            'deposit_rate' => 20.0,
        ]);

        $room = BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 5,
            'base_rate' => 1000,
            'currency' => 'NAD',
        ]);

        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-12'))],
            guestName: 'Test Guest',
            guestEmail: $email,
            source: ReservationSource::WalkIn,
        ));
    }
}
