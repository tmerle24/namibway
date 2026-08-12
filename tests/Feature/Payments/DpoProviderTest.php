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
use Tests\TestCase;

/**
 * DPO Pay, against a faked HTTP client — no network, no account.
 *
 * DPO is the one candidate that actually covers Namibia: it operates there,
 * and it bills and settles in NAD. See DpoProvider for how the alternatives
 * were ruled out.
 *
 * What these tests pin down are the four things that would be wrong silently:
 * NAD is charged as NAD with no conversion, the XML carries our own reference
 * with `CompanyRefUnique` so a retry cannot open a second transaction, an
 * unsigned notification settles nothing on its own, and — the important one —
 * an unrecognised result code leaves the attempt **open** rather than failing
 * it.
 */
class DpoProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payments.default_provider', 'dpo');
        config()->set('payments.providers.dpo.company_token', 'company-token-abc');
        config()->set('payments.providers.dpo.service_type', '3854');

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_nad_folio_is_charged_in_nad_with_no_conversion(): void
    {
        $this->fakeDpo();

        $stay = $this->stay();
        $intent = app(PaymentGateway::class)->start($stay, PaymentPurpose::Deposit);

        // The whole reason this provider is the one to use.
        $this->assertSame('NAD', $intent->currency);
        $this->assertSame('NAD', $intent->charge_currency);
        $this->assertSame(1.0, $intent->exchange_rate);
        $this->assertFalse($intent->isConverted());

        $this->assertSame(
            'https://secure.3gdirectpay.com/payv3.php?ID=trans-token-xyz',
            $intent->meta['redirect_url'] ?? null,
        );
        $this->assertSame('DPO-REF-1', $intent->provider_reference);
    }

    public function test_the_created_transaction_carries_our_own_unique_reference(): void
    {
        $this->fakeDpo();

        $stay = $this->stay();
        $intent = app(PaymentGateway::class)->start($stay, PaymentPurpose::Deposit);

        Http::assertSent(function ($request) use ($intent): bool {
            $body = (string) $request->body();

            return str_contains($request->url(), '/API/v6/')
                && str_contains($body, '<Request>createToken</Request>')
                && str_contains($body, '<CompanyToken>company-token-abc</CompanyToken>')
                && str_contains($body, '<PaymentCurrency>NAD</PaymentCurrency>')
                && str_contains($body, '<PaymentAmount>'.number_format($intent->charge_amount, 2, '.', '').'</PaymentAmount>')
                // Ours, and unique — which is what turns a retried
                // createToken into a refusal rather than a second transaction.
                && str_contains($body, '<CompanyRef>'.$intent->reference.'</CompanyRef>')
                && str_contains($body, '<CompanyRefUnique>1</CompanyRefUnique>')
                && str_contains($body, '<ServiceType>3854</ServiceType>');
        });
    }

    /**
     * SimpleXMLElement does not escape what it is given, so a property called
     * "Sun & Sand" would otherwise produce a document DPO cannot parse.
     */
    public function test_an_ampersand_in_the_property_name_does_not_break_the_document(): void
    {
        $this->fakeDpo();

        $stay = $this->stay(propertyName: 'Sun & Sand Lodge');
        app(PaymentGateway::class)->start($stay, PaymentPurpose::Deposit);

        Http::assertSent(function ($request): bool {
            $body = (string) $request->body();

            return str_contains($body, 'Sun &amp; Sand Lodge')
                && simplexml_load_string($body) !== false;
        });
    }

    public function test_a_paid_transaction_becomes_exactly_one_payment(): void
    {
        $this->fakeDpo(verifyResult: '000', verifyExplanation: 'Transaction Paid');

        $stay = $this->stay();
        $intent = $this->startIntent($stay);

        app(PaymentGateway::class)->settle($intent);

        $intent->refresh();
        $stay->refresh();

        $this->assertSame(PaymentIntentStatus::Succeeded, $intent->status);
        $this->assertSame(1, Payment::query()->where('reservation_id', $stay->id)->count());
        $this->assertSame($intent->amount, $stay->paid_amount);
        // No conversion, so the received_* columns stay out of the row.
        $this->assertNull($stay->payments()->sole()->received_amount);
    }

    /**
     * The decision this class is most opinionated about. DPO's non-paid codes
     * could not be verified from public documentation, and a code mapped to
     * "failed" on a guess writes off money that may have arrived.
     */
    public function test_an_unrecognised_result_code_leaves_the_attempt_open(): void
    {
        $this->fakeDpo(verifyResult: '904', verifyExplanation: 'Request missing data');

        $stay = $this->stay();
        $intent = $this->startIntent($stay);

        app(PaymentGateway::class)->settle($intent);

        $intent->refresh();

        $this->assertSame(PaymentIntentStatus::Pending, $intent->status);
        $this->assertSame(0, Payment::count());
        // And it says what it was told, so somebody debugging can see it.
        $this->assertStringContainsString('904', (string) ($intent->meta['outcome_message'] ?? ''));
    }

    /** A gateway answering with an HTML error page must not become an exception. */
    public function test_an_unparseable_answer_leaves_the_attempt_open(): void
    {
        $this->fakeDpo();

        $stay = $this->stay();
        $intent = $this->startIntent($stay);

        // The gateway falls over between the two calls and answers with a
        // proxy's error page rather than a document.
        Http::fake(['*' => Http::response('<html><body>502 Bad Gateway</body></html>')]);

        app(PaymentGateway::class)->settle($intent);

        $this->assertSame(PaymentIntentStatus::Pending, $intent->fresh()?->status);
        $this->assertSame(0, Payment::count());
    }

    /**
     * DPO's notification is not signed, so it may not settle anything by
     * itself. It only says "worth asking about"; verifyToken decides.
     */
    public function test_an_unsigned_notification_settles_nothing_on_its_own(): void
    {
        // DPO still reports the transaction as unpaid.
        $this->fakeDpo(verifyResult: '901', verifyExplanation: 'Transaction not paid yet');

        $stay = $this->stay();
        $intent = $this->startIntent($stay);

        $this->post(route('payments.callback', ['provider' => 'dpo']), [
            'CompanyRef' => $intent->reference,
        ])->assertOk();

        $this->assertSame(PaymentIntentStatus::Pending, $intent->fresh()?->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_notification_is_what_prompts_the_verify_that_does_settle_it(): void
    {
        $this->fakeDpo(verifyResult: '000', verifyExplanation: 'Transaction Paid');

        $stay = $this->stay();
        $intent = $this->startIntent($stay);

        $this->post(route('payments.callback', ['provider' => 'dpo']), [
            'CompanyRef' => $intent->reference,
        ])->assertOk();

        $this->assertSame(PaymentIntentStatus::Succeeded, $intent->fresh()?->status);
        $this->assertSame(1, Payment::count());
    }

    public function test_a_refused_create_token_is_reported_rather_than_producing_a_dead_link(): void
    {
        Http::fake([
            '*/API/v6/' => Http::response($this->xml([
                'Result' => '801',
                'ResultExplanation' => 'Request missing company token',
            ])),
        ]);

        $stay = $this->stay();

        $this->expectExceptionMessage('DPO would not create the transaction');

        app(PaymentGateway::class)->start($stay, PaymentPurpose::Deposit);
    }

    public function test_a_refund_goes_back_at_the_rate_it_was_charged_at(): void
    {
        $this->fakeDpo(verifyResult: '000', verifyExplanation: 'Transaction Paid');

        $stay = $this->stay();
        $intent = $this->startIntent($stay);

        app(PaymentGateway::class)->settle($intent);

        $payment = $stay->fresh()?->payments()->sole();
        $this->assertNotNull($payment);

        $refund = app(PaymentGateway::class)->refund($payment, 100.0);

        $this->assertSame(-100.0, $refund->amount);

        Http::assertSent(function ($request): bool {
            $body = (string) $request->body();

            return str_contains($body, '<Request>refundToken</Request>')
                && str_contains($body, '<TransactionToken>trans-token-xyz</TransactionToken>')
                && str_contains($body, '<refundAmount>100.00</refundAmount>');
        });
    }

    public function test_the_factory_refuses_dpo_without_a_company_token(): void
    {
        config()->set('payments.providers.dpo.company_token', null);

        $this->expectExceptionMessage('DPO_COMPANY_TOKEN');

        app(PaymentProviderFactory::class)->make('dpo');
    }

    public function test_dpo_is_offered_as_a_choice_and_named_for_what_it_covers(): void
    {
        $options = app(PaymentProviderFactory::class)->options();

        $this->assertArrayHasKey('dpo', $options);
        $this->assertStringContainsString('Namibia', $options['dpo']);
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * DPO is one endpoint that does four different things depending on the
     * `<Request>` element, so the fake routes on that rather than on the URL.
     *
     * One `Http::fake()` for the whole test, deliberately: Laravel *merges*
     * successive fakes and the first matching stub wins, so faking the same
     * URL pattern twice would answer a verifyToken with the createToken
     * response — which is exactly the false green this comment exists to
     * prevent, and which this test did produce before the fake routed on the
     * request type.
     */
    private function fakeDpo(?string $verifyResult = null, ?string $verifyExplanation = null, ?string $refundResult = null): void
    {
        Http::fake(function ($request) use ($verifyResult, $verifyExplanation, $refundResult) {
            $body = (string) $request->body();

            if (str_contains($body, '<Request>createToken</Request>')) {
                return Http::response($this->xml([
                    'Result' => '000',
                    'ResultExplanation' => 'Transaction created',
                    'TransToken' => 'trans-token-xyz',
                    'TransRef' => 'DPO-REF-1',
                ]));
            }

            if (str_contains($body, '<Request>refundToken</Request>')) {
                return Http::response($this->xml([
                    'Result' => $refundResult ?? '000',
                    'ResultExplanation' => 'refund Successful',
                ]));
            }

            $result = $verifyResult ?? '901';

            return Http::response($this->xml(array_filter([
                'Result' => $result,
                'ResultExplanation' => $verifyExplanation ?? 'Transaction not paid yet',
                'TransactionRef' => $result === '000' ? 'DPO-REF-1' : null,
                'TransactionApproval' => $result === '000' ? 'APPROVAL-9' : null,
            ], fn (?string $value): bool => $value !== null)));
        });
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function xml(array $fields): string
    {
        $body = '<?xml version="1.0" encoding="utf-8"?><API3G>';

        foreach ($fields as $name => $value) {
            $body .= "<{$name}>".htmlspecialchars($value, ENT_XML1)."</{$name}>";
        }

        return $body.'</API3G>';
    }

    private function startIntent(Reservation $stay): PaymentIntent
    {
        return app(PaymentGateway::class)->start($stay, PaymentPurpose::Deposit);
    }

    private function stay(string $propertyName = 'Etosha Camp'): Reservation
    {
        $partner = Partner::create(['name' => $propertyName, 'email' => fake()->unique()->safeEmail()]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'name' => $propertyName,
            'is_published' => true,
            'deposit_rate' => 20.0,
        ]);

        $room = BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 5,
            'rate_per_night' => 1000,
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
