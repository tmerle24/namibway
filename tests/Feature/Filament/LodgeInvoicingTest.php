<?php

namespace Tests\Feature\Filament;

use App\Enums\InvoiceKind;
use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Filament\Partner\Pages\ArrivalsDepartures;
use App\Models\BookableUnit;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Payments\InvoiceIssuer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Issuing and crediting from the lodge screens, and — the part that matters
 * most — who is allowed to read the resulting document.
 *
 * Invoice numbers are sequential by design, so a missing check on the PDF
 * route would let one property read every other property's takings by counting
 * upwards. That is the test this class exists for.
 */
class LodgeInvoicingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_invoice_is_issued_from_the_stay_drawer(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $stay = $this->stay($listing, $this->bookableUnit($listing));

        $this->asPartner($user);

        Livewire::test(ArrivalsDepartures::class)
            ->call('showReservation', $stay->id)
            ->callAction('issueInvoice')
            ->assertHasNoActionErrors();

        $invoice = Invoice::query()->where('reservation_id', $stay->id)->sole();

        $this->assertSame(InvoiceKind::Stay, $invoice->kind);
        $this->assertSame('Etosha Camp', $invoice->issuer_name);
        $this->assertSame($user->id, $invoice->issued_by);
        $this->assertSame(1, $invoice->sequence_number);
    }

    public function test_an_invoice_is_credited_from_the_stay_drawer(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $stay = $this->stay($listing, $this->bookableUnit($listing));
        $invoice = app(InvoiceIssuer::class)->issueForStay($stay);

        $this->asPartner($user);

        Livewire::test(ArrivalsDepartures::class)
            ->call('showReservation', $stay->id)
            ->call('selectInvoice', $invoice->id)
            ->assertSet('selectedInvoiceId', $invoice->id)
            ->callAction('creditInvoice', ['reason' => 'Wrong dates.'])
            ->assertHasNoActionErrors();

        $this->assertTrue($invoice->fresh()?->isCancelled());
        $this->assertSame(2, Invoice::query()->where('reservation_id', $stay->id)->count());
    }

    public function test_an_invoice_belonging_to_another_partner_cannot_be_selected(): void
    {
        [$mine, $myListing] = $this->partnerWithProperty('Mine');
        $myStay = $this->stay($myListing, $this->bookableUnit($myListing));

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $theirStay = $this->stay($theirListing, $this->bookableUnit($theirListing));
        $theirInvoice = app(InvoiceIssuer::class)->issueForStay($theirStay);

        $this->asPartner($mine);

        Livewire::test(ArrivalsDepartures::class)
            ->call('showReservation', $myStay->id)
            ->call('selectInvoice', $theirInvoice->id)
            ->assertSet('selectedInvoiceId', null);
    }

    public function test_a_property_can_read_its_own_invoice(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $invoice = app(InvoiceIssuer::class)->issueForStay($this->stay($listing, $this->bookableUnit($listing)));

        $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent() ?: '');
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_property_cannot_read_another_propertys_invoice(): void
    {
        [$mine] = $this->partnerWithProperty('Mine');

        [, $theirListing] = $this->partnerWithProperty('Theirs');
        $theirInvoice = app(InvoiceIssuer::class)->issueForStay(
            $this->stay($theirListing, $this->bookableUnit($theirListing))
        );

        $this->actingAs($mine)->get(route('invoices.pdf', $theirInvoice))->assertForbidden();
    }

    public function test_a_signed_out_visitor_cannot_read_an_invoice(): void
    {
        [, $listing] = $this->partnerWithProperty('Etosha Camp');
        $invoice = app(InvoiceIssuer::class)->issueForStay($this->stay($listing, $this->bookableUnit($listing)));

        $this->get(route('invoices.pdf', $invoice))->assertRedirect();
    }

    public function test_our_own_staff_can_read_any_invoice(): void
    {
        [, $listing] = $this->partnerWithProperty('Etosha Camp');
        $invoice = app(InvoiceIssuer::class)->issueForStay($this->stay($listing, $this->bookableUnit($listing)));

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('invoices.pdf', $invoice))->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    private function asPartner(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function partnerWithProperty(string $name): array
    {
        $partner = Partner::create(['name' => $name, 'email' => fake()->unique()->safeEmail()]);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => $name,
        ]);

        return [$user, $listing];
    }

    private function bookableUnit(Listing $listing): BookableUnit
    {
        return BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 3,
            'rate_per_night' => 1500.00,
            'currency' => 'NAD',
            'is_active' => true,
        ]);
    }

    private function stay(Listing $listing, BookableUnit $room): Reservation
    {
        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-12'))],
            guestName: 'Test Guest',
            guestEmail: 'guest@example.com',
            source: ReservationSource::WalkIn,
        ));
    }
}
