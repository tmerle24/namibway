<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentCollector;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
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
use App\Services\Payments\DTOs\PaymentRequest;
use App\Services\Payments\MoneyWriteGuard;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentRecorder;
use App\Services\Payments\Providers\DemoProvider;
use App\Services\Payments\Providers\PaymentProviderFactory;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The acceptance criteria of PAYMENTS_BUILD.md slice 5, exercised entirely
 * through the demo provider — no network, no account, no gateway.
 *
 * The two that matter most are about the *callback*: delivered twice, and
 * arriving before the guest gets back. That ordering is where real
 * integrations break, and it is the reason the demo does it on purpose.
 */
class PaymentProviderTest extends TestCase
{
    use RefreshDatabase;

    private PaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payments.default_provider', 'demo');

        $this->gateway = app(PaymentGateway::class);
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_happy_path_produces_exactly_one_payment(): void
    {
        $stay = $this->stay();
        $intent = $this->gateway->start($stay, PaymentPurpose::Deposit);

        $this->assertSame(PaymentIntentStatus::Pending, $intent->status);

        $this->post(route('payments.decide', $intent), ['decision' => 'pay'])
            ->assertRedirect(route('payments.return', $intent));

        $intent->refresh();
        $stay->refresh();

        $this->assertSame(PaymentIntentStatus::Succeeded, $intent->status);
        $this->assertSame(1, Payment::query()->where('reservation_id', $stay->id)->count());

        $payment = $stay->payments()->sole();

        $this->assertSame($intent->amount, $payment->amount);
        $this->assertSame(PaymentMethod::Online, $payment->method);
        $this->assertSame(PaymentCollector::NamibWay, $payment->collected_by);
        // The gateway confirmed it, which is exactly what `cleared` means.
        $this->assertSame(PaymentStatus::Cleared, $payment->status);
        $this->assertSame($intent->id, $payment->payment_intent_id);
        $this->assertSame($intent->amount, $stay->paid_amount);
    }

    /**
     * The decision, written down. A declined attempt produces **no payment
     * row** — `payments` is money that moved, and `payment_intents` is money
     * we asked for. A guest who mistypes a card three times leaves three
     * attempts and no ledger noise, and "how much came in" stays a question
     * the payments table alone can answer.
     *
     * `PaymentStatus::Failed` still exists, for the different case: an EFT
     * somebody recorded as received and the bank did not honour. Money
     * believed to have moved is not money that never started moving.
     */
    public function test_a_declined_payment_produces_no_payment_row(): void
    {
        $stay = $this->stay();
        $intent = $this->gateway->start($stay, PaymentPurpose::Deposit);

        $this->post(route('payments.decide', $intent), ['decision' => 'decline']);

        $intent->refresh();
        $stay->refresh();

        $this->assertSame(PaymentIntentStatus::Failed, $intent->status);
        $this->assertSame(0, Payment::query()->where('reservation_id', $stay->id)->count());
        $this->assertSame(0.0, $stay->paid_amount);
        // The attempt records why, so a desk asked "did they try?" can answer.
        $this->assertSame('The demo card was declined.', $intent->meta['outcome_message'] ?? null);
    }

    public function test_an_abandoned_payment_leaves_the_booking_untouched(): void
    {
        $stay = $this->stay();
        $intent = $this->gateway->start($stay, PaymentPurpose::Deposit);

        $this->post(route('payments.decide', $intent), ['decision' => 'abandon']);

        $this->assertSame(PaymentIntentStatus::Abandoned, $intent->fresh()?->status);
        $this->assertSame(0.0, $stay->fresh()?->paid_amount);
    }

    /**
     * A gateway delivering the same webhook twice is normal operation, not a
     * fault. `payments.payment_intent_id` is unique, so the second delivery
     * cannot credit the guest again.
     */
    public function test_a_callback_delivered_twice_produces_one_payment(): void
    {
        $stay = $this->stay();
        $intent = $this->gateway->start($stay, PaymentPurpose::Deposit);

        $this->markDecision($intent, 'pay');

        $payload = ['reference' => $intent->reference, 'decision' => 'pay'];

        $this->postJson(route('payments.callback', ['provider' => 'demo']), $payload)->assertOk();
        $this->postJson(route('payments.callback', ['provider' => 'demo']), $payload)->assertOk();
        $this->postJson(route('payments.callback', ['provider' => 'demo']), $payload)->assertOk();

        $this->assertSame(1, Payment::query()->where('reservation_id', $stay->id)->count());
        $this->assertSame($intent->amount, $stay->fresh()?->paid_amount);
    }

    /**
     * The ordering real integrations get wrong: the webhook lands while the
     * guest's browser is still following a redirect.
     */
    public function test_a_callback_arriving_before_the_guest_returns_still_works(): void
    {
        $stay = $this->stay();
        $intent = $this->gateway->start($stay, PaymentPurpose::Deposit);

        $this->markDecision($intent, 'pay');

        // Webhook first.
        $this->postJson(route('payments.callback', ['provider' => 'demo']), [
            'reference' => $intent->reference,
            'decision' => 'pay',
        ])->assertOk();

        $this->assertSame(1, Payment::query()->where('reservation_id', $stay->id)->count());

        // Guest second. The page still shows them a paid booking, and no
        // second payment appears.
        $this->get(route('payments.return', $intent))
            ->assertOk()
            ->assertSee('Thank you', false);

        $this->assertSame(1, Payment::query()->where('reservation_id', $stay->id)->count());
    }

    public function test_a_callback_for_an_unknown_attempt_is_accepted_and_ignored(): void
    {
        // 200 rather than an error: a gateway that gets errors stops
        // delivering, and an event about something we do not have is not a
        // failure on our side.
        $this->postJson(route('payments.callback', ['provider' => 'demo']), [
            'reference' => 'nothing-like-this',
            'decision' => 'pay',
        ])->assertOk();

        $this->assertSame(0, Payment::count());
    }

    public function test_an_unknown_provider_in_a_callback_url_is_a_404(): void
    {
        $this->postJson(route('payments.callback', ['provider' => 'not-a-gateway']), [])
            ->assertNotFound();
    }

    public function test_a_refund_through_the_provider_lands_as_a_negative_payment(): void
    {
        $stay = $this->stay();
        $intent = $this->gateway->start($stay, PaymentPurpose::Deposit);

        $this->post(route('payments.decide', $intent), ['decision' => 'pay']);

        $payment = $stay->fresh()?->payments()->sole();
        $this->assertNotNull($payment);

        $refund = $this->gateway->refund($payment, 100.0);

        $this->assertSame(-100.0, $refund->amount);
        $this->assertSame(PaymentMethod::Online, $refund->method);
        $this->assertSame(
            Money::cents($intent->amount) - 10_000,
            Money::cents((float) $stay->fresh()?->paid_amount),
        );
    }

    public function test_a_payment_taken_at_the_desk_cannot_be_refunded_through_a_provider(): void
    {
        $stay = $this->stay();

        $payment = app(PaymentRecorder::class)->record(
            new PaymentRequest(
                reservation: $stay,
                amount: 500.0,
                method: PaymentMethod::Cash,
                collectedBy: PaymentCollector::Partner,
            )
        );

        $this->expectException(InvalidArgumentException::class);
        $this->gateway->refund($payment);
    }

    public function test_an_open_attempt_is_reused_rather_than_duplicated(): void
    {
        $stay = $this->stay();

        $first = $this->gateway->start($stay, PaymentPurpose::Deposit);
        $second = $this->gateway->start($stay, PaymentPurpose::Deposit);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PaymentIntent::query()->where('reservation_id', $stay->id)->count());
    }

    public function test_a_settled_attempt_cannot_be_paid_again(): void
    {
        $stay = $this->stay();
        $intent = $this->gateway->start($stay, PaymentPurpose::Deposit);

        $this->post(route('payments.decide', $intent), ['decision' => 'pay']);

        // The checkout page redirects rather than offering the buttons again.
        $this->get(route('payments.checkout', $intent))
            ->assertRedirect(route('payments.return', $intent));

        $this->assertSame(1, Payment::query()->where('reservation_id', $stay->id)->count());
    }

    public function test_a_stay_with_no_deposit_cannot_be_asked_for_one(): void
    {
        $stay = $this->stay(depositRate: 0.0, allowZeroDeposit: true);

        $this->expectException(InvalidArgumentException::class);
        $this->gateway->start($stay, PaymentPurpose::Deposit);
    }

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    */

    /**
     * The folio is in NAD and no provider available to us settles NAD, so
     * something has to give. All three currency facts are stored.
     */
    public function test_a_provider_that_cannot_settle_the_folios_currency_charges_the_pegged_one(): void
    {
        $stay = $this->stay();

        $intent = $this->gateway->start(
            $stay,
            PaymentPurpose::Deposit,
            provider: new class extends DemoProvider
            {
                /** @return array<int, string> */
                public function supportedCurrencies(): array
                {
                    return ['ZAR'];
                }
            },
        );

        $this->assertSame('NAD', $intent->currency);
        $this->assertSame('ZAR', $intent->charge_currency);
        $this->assertSame(1.0, $intent->exchange_rate);
        $this->assertSame($intent->amount, $intent->charge_amount);
        $this->assertTrue($intent->isConverted());

        $this->post(route('payments.decide', $intent), ['decision' => 'pay']);

        $payment = $stay->fresh()?->payments()->sole();

        // What was owed, what the guest handed over, and the rate — all three.
        $this->assertSame($intent->amount, $payment?->amount);
        $this->assertSame('NAD', $payment?->currency);
        $this->assertSame($intent->charge_amount, $payment?->received_amount);
        $this->assertSame('ZAR', $payment?->received_currency);
        $this->assertSame(1.0, $payment?->exchange_rate);
    }

    /*
    |--------------------------------------------------------------------------
    | The abstraction itself
    |--------------------------------------------------------------------------
    */

    /**
     * The claim slice 5 has to be able to make. A provider abstraction whose
     * name leaks upward is not one.
     */
    public function test_nothing_above_the_provider_interface_names_a_provider(): void
    {
        $offences = [];

        // Where naming a gateway is legitimate: the implementations
        // themselves, the factory that chooses between them, and config.
        $allowed = [
            'app/Services/Payments/Providers',
            'config/payments.php',
        ];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $file->getRealPath());

            foreach ($allowed as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }

            $contents = File::get($file->getRealPath());

            foreach (['Paystack', 'Stripe', 'DpoPay', 'PayGate', 'Ozow'] as $gateway) {
                if (str_contains($contents, $gateway)) {
                    $offences[] = "{$relative} names [{$gateway}].";
                }
            }
        }

        $this->assertSame(
            [],
            $offences,
            "A gateway is named above App\\Services\\Payments\\Providers.\n"
            .'Everything else has to go through the PaymentProvider interface:'
            ."\n - ".implode("\n - ", $offences)
        );
    }

    public function test_every_configured_provider_can_be_built_or_says_why_not(): void
    {
        $factory = app(PaymentProviderFactory::class);

        $this->assertSame('demo', $factory->default()->key());
        $this->assertArrayHasKey('paystack', $factory->options());

        // Selected without credentials, the factory refuses with a sentence
        // rather than producing a client that fails at the gateway.
        config()->set('payments.providers.paystack.secret_key', null);

        $this->expectException(InvalidArgumentException::class);
        $factory->make('paystack');
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /** The demo checkout writes this; a test that skips the page has to too. */
    private function markDecision(PaymentIntent $intent, string $decision): void
    {
        MoneyWriteGuard::allow(function () use ($intent, $decision): void {
            $intent->meta = array_merge($intent->meta ?? [], ['decision' => $decision]);
            $intent->save();
        });
    }

    private function stay(float $depositRate = 20.0, bool $allowZeroDeposit = false): Reservation
    {
        $partner = Partner::create([
            'name' => 'Etosha Camp',
            'email' => fake()->unique()->safeEmail(),
            'allow_zero_deposit' => $allowZeroDeposit,
        ]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'name' => 'Etosha Camp',
            'is_published' => true,
            'deposit_rate' => $depositRate,
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
            guestEmail: 'guest@example.com',
            source: ReservationSource::WalkIn,
        ));
    }
}
