<?php

namespace Tests\Feature\Booking;

use App\Enums\InquiryKind;
use App\Enums\InquiryStatus;
use App\Enums\ListingType;
use App\Enums\PaymentPurpose;
use App\Mail\GuestBookingConfirmed;
use App\Mail\GuestPaymentRequest;
use App\Models\BookableUnit;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Services\Booking\InquiryDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Confirming a request and asking for the deposit in one press.
 *
 * The thing being protected here is that the guest gets **one** email. A
 * confirmation followed seconds later by a separate "and here is a link" is a
 * system talking to itself, and it is what this flow exists to avoid. The other
 * property that matters: asking for money can fail where confirming cannot — an
 * unpriced stay, a request that could not go on the calendar — and a failure
 * there must leave the confirmation standing and tell the partner what happened.
 */
class ConfirmWithPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-12 09:00:00'));
        Mail::fake();

        $partner = Partner::create(['name' => 'Dune Edge', 'email' => 'stay@duneedge.test']);

        $this->listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
        ]);

        BookableUnit::factory()->create([
            'listing_id' => $this->listing->id,
            'code' => 'STD',
            'total_units' => 2,
            'base_rate' => 1200,
            'currency' => 'NAD',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_one_email_carries_the_confirmation_the_message_and_the_payment_link(): void
    {
        $inquiry = $this->inquiry();

        $outcome = app(InquiryDecisionService::class)->confirmAndRequestDeposit($inquiry, 'See you in September.');

        $this->assertTrue($outcome->handled);
        $this->assertTrue($outcome->askedForPayment());
        $this->assertNull($outcome->problem);

        Mail::assertQueued(GuestBookingConfirmed::class, function (GuestBookingConfirmed $mail): bool {
            return $mail->hasTo('anna@example.com')
                && $mail->paymentUrl !== null
                && $mail->partnerMessage === 'See you in September.';
        });

        // One mail to the guest, not two. Counted per mailable rather than in
        // total: creating the request already sent the partner theirs.
        Mail::assertQueued(GuestBookingConfirmed::class, 1);
        Mail::assertNotQueued(GuestPaymentRequest::class);
    }

    public function test_it_asks_for_the_deposit_and_not_the_whole_stay(): void
    {
        app(InquiryDecisionService::class)->confirmAndRequestDeposit($this->inquiry());

        $intent = PaymentIntent::sole();
        $stay = Reservation::sole();

        $this->assertSame(PaymentPurpose::Deposit, $intent->purpose);
        $this->assertSame($stay->id, $intent->reservation_id);
        $this->assertEqualsWithDelta((float) $stay->deposit_amount, $intent->amount, 0.01);
        $this->assertGreaterThan(0, $intent->amount);
    }

    /**
     * The confirmation is the guest's, and it is not taken back because the
     * money side could not be set up. The partner is told instead.
     */
    public function test_a_request_that_cannot_become_a_stay_is_still_confirmed(): void
    {
        $inquiry = $this->inquiry(['check_in' => null, 'check_out' => null, 'travel_dates' => 'mid-September']);

        $outcome = app(InquiryDecisionService::class)->confirmAndRequestDeposit($inquiry, 'Looking forward to it.');

        $this->assertTrue($outcome->handled);
        $this->assertFalse($outcome->askedForPayment());
        $this->assertNotNull($outcome->problem);
        $this->assertSame(InquiryStatus::Confirmed, $inquiry->refresh()->status);
        $this->assertSame(0, PaymentIntent::count());

        // The partner's own words still reach the guest — they were written to
        // a person, not to the payment provider.
        Mail::assertQueued(GuestBookingConfirmed::class, function (GuestBookingConfirmed $mail): bool {
            return $mail->paymentUrl === null && $mail->partnerMessage === 'Looking forward to it.';
        });
    }

    public function test_a_request_already_answered_is_not_answered_again(): void
    {
        $inquiry = $this->inquiry(['status' => InquiryStatus::Confirmed]);

        $outcome = app(InquiryDecisionService::class)->confirmAndRequestDeposit($inquiry);

        $this->assertFalse($outcome->handled);
        $this->assertSame(0, PaymentIntent::count());
        Mail::assertNotQueued(GuestBookingConfirmed::class);
    }

    /**
     * A mail client that prefetches links must not be able to confirm a booking
     * and take money by opening the message. The signed link renders a page; the
     * form on it is what acts.
     */
    public function test_the_emailed_link_only_opens_a_page(): void
    {
        $inquiry = $this->inquiry();

        $this->get($this->signedUrl($inquiry))->assertOk();

        $this->assertSame(InquiryStatus::OnRequest, $inquiry->refresh()->status);
        $this->assertSame(0, PaymentIntent::count());
    }

    public function test_posting_the_form_confirms_and_sends_the_link(): void
    {
        $inquiry = $this->inquiry();

        $this->post($this->signedUrl($inquiry), ['message' => 'Bring a warm jacket.'])->assertOk();

        $this->assertSame(InquiryStatus::Confirmed, $inquiry->refresh()->status);
        $this->assertSame(1, PaymentIntent::count());

        Mail::assertQueued(GuestBookingConfirmed::class, fn (GuestBookingConfirmed $mail): bool => $mail->partnerMessage === 'Bring a warm jacket.'
        );
    }

    /**
     * A deposit is asked for against a stay, and an order never becomes one.
     * The button used to be offered anyway — it confirmed the order and then
     * told the business the confirmation "could not be put on the calendar" and
     * that the team had been alerted, over a request that behaved normally.
     *
     * Taking money for an order is a real thing this will want. It needs a
     * payment intent that hangs off a request rather than off a reservation,
     * which the money side does not have; until it does, not offering the
     * button is the honest answer.
     */
    public function test_an_order_is_never_offered_the_deposit_button(): void
    {
        $order = $this->inquiry([
            'kind' => InquiryKind::Order,
            'check_in' => null,
            'check_out' => null,
            'bookable_unit_code' => null,
        ]);

        $this->get($this->signedUrl($order))->assertNotFound();
        $this->post($this->signedUrl($order), ['message' => 'Ready in 20 minutes.'])->assertNotFound();

        $this->assertSame(InquiryStatus::OnRequest, $order->refresh()->status);
        $this->assertSame(0, PaymentIntent::count());
    }

    public function test_an_unsigned_address_is_refused(): void
    {
        $inquiry = $this->inquiry();

        $this->post("/partner/inquiries/{$inquiry->id}/confirm-with-payment")->assertForbidden();

        $this->assertSame(InquiryStatus::OnRequest, $inquiry->refresh()->status);
    }

    private function signedUrl(Inquiry $inquiry): string
    {
        return URL::signedRoute(
            'partner.inquiries.confirm-with-payment',
            ['inquiry' => $inquiry->id],
            now()->addDays(3),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function inquiry(array $attributes = []): Inquiry
    {
        return Inquiry::create([
            'listing_id' => $this->listing->id,
            'name' => 'Anna Shipanga',
            'email' => 'anna@example.com',
            'phone' => '081 234 5678',
            'check_in' => '2026-09-10',
            'check_out' => '2026-09-13',
            'adults' => 2,
            'children' => 0,
            'status' => InquiryStatus::OnRequest,
            'bookable_unit_code' => 'STD',
            ...$attributes,
        ]);
    }
}
