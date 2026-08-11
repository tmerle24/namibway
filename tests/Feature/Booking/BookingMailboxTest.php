<?php

namespace Tests\Feature\Booking;

use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Jobs\NotifyAboutStay;
use App\Mail\GuestStayCancelled;
use App\Mail\GuestStayConfirmed;
use App\Mail\PartnerStayRecorded;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Booking\BookingMailbox;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Where a booking's mail goes, and — more to the point — where it does not.
 *
 * Two failures this exists to prevent, both of which look like nothing at all
 * until somebody arrives expecting a room: a lodge that has not been switched
 * on sending a confirmation to a real guest in NamibWay's name, and an
 * operator trying the system against their own inventory mailing a real guest
 * by accident.
 */
class BookingMailboxTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $attributes */
    private function property(array $attributes = [], string $slug = 'okonjima-bush-camp'): Listing
    {
        $partner = Partner::create([
            'name' => 'Okonjima',
            'email' => 'info@okonjima.test',
            ...$attributes,
        ]);

        return Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => 'Okonjima Bush Camp',
            'slug' => $slug,
        ]);
    }

    private function book(Listing $listing, ?string $guestEmail = 'guest@example.test'): Reservation
    {
        $room = RoomType::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 2,
            'rate_per_night' => 1500,
            'currency' => 'NAD',
        ]);

        return app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-12'))],
            guestName: 'Real Guest',
            guestEmail: $guestEmail,
            source: ReservationSource::WalkIn,
        ));
    }

    public function test_a_property_that_is_not_live_writes_to_nobody_but_us(): void
    {
        Mail::fake();

        $listing = $this->property();
        $this->book($listing);

        Mail::assertSent(GuestStayConfirmed::class, fn (GuestStayConfirmed $mail) => $mail->hasTo('team+okonjima-bush-camp@namibway.com'));
        Mail::assertSent(PartnerStayRecorded::class, fn (PartnerStayRecorded $mail) => $mail->hasTo('team+okonjima-bush-camp@namibway.com'));

        // And nothing at all reached the guest or the lodge.
        Mail::assertNotSent(GuestStayConfirmed::class, fn (GuestStayConfirmed $mail) => $mail->hasTo('guest@example.test'));
        Mail::assertNotSent(PartnerStayRecorded::class, fn (PartnerStayRecorded $mail) => $mail->hasTo('info@okonjima.test'));
    }

    public function test_test_mode_sends_everything_to_the_address_the_operator_chose(): void
    {
        Mail::fake();

        $listing = $this->property([
            'booking_enabled' => true,
            'booking_demo_mode' => true,
            'booking_demo_email' => 'reception@okonjima.test',
        ]);

        $this->book($listing);

        // Test mode wins over the live switch, on purpose.
        Mail::assertSent(GuestStayConfirmed::class, fn (GuestStayConfirmed $mail) => $mail->hasTo('reception@okonjima.test'));
        Mail::assertSent(PartnerStayRecorded::class, fn (PartnerStayRecorded $mail) => $mail->hasTo('reception@okonjima.test'));
        Mail::assertNotSent(GuestStayConfirmed::class, fn (GuestStayConfirmed $mail) => $mail->hasTo('guest@example.test'));
    }

    public function test_a_live_property_writes_to_the_guest_and_to_its_own_desk(): void
    {
        Mail::fake();

        $listing = $this->property([
            'booking_enabled' => true,
            'booking_email' => 'reservations@okonjima.test',
        ]);

        $this->book($listing);

        Mail::assertSent(GuestStayConfirmed::class, fn (GuestStayConfirmed $mail) => $mail->hasTo('guest@example.test'));
        Mail::assertSent(PartnerStayRecorded::class, fn (PartnerStayRecorded $mail) => $mail->hasTo('reservations@okonjima.test'));
    }

    public function test_a_live_property_with_no_booking_address_falls_back_rather_than_dropping_mail(): void
    {
        $listing = $this->property(['booking_enabled' => true]);

        $mailbox = new BookingMailbox($listing);

        // The contact address, and then us. A booking nobody was told about is
        // worse than one told to the wrong desk.
        $this->assertSame('info@okonjima.test', $mailbox->property());

        $listing->partner?->update(['email' => null]);

        $reloaded = Listing::findOrFail($listing->id);

        $this->assertSame('team+okonjima-bush-camp@namibway.com', (new BookingMailbox($reloaded))->property());
    }

    public function test_a_telephone_booking_with_no_guest_address_still_tells_the_property(): void
    {
        Mail::fake();

        $listing = $this->property(['booking_enabled' => true]);
        $this->book($listing, null);

        Mail::assertNotSent(GuestStayConfirmed::class);
        Mail::assertSent(PartnerStayRecorded::class);
    }

    public function test_cancelling_tells_the_guest_and_nobody_else_by_accident(): void
    {
        Mail::fake();

        $listing = $this->property();
        $reservation = $this->book($listing);

        app(InventoryWriter::class)->cancel($reservation, 'Guest called');

        Mail::assertSent(GuestStayCancelled::class, fn (GuestStayCancelled $mail) => $mail->hasTo('team+okonjima-bush-camp@namibway.com'));
    }

    public function test_a_booking_that_asks_not_to_be_announced_is_not_announced(): void
    {
        Mail::fake();

        $listing = $this->property(['booking_enabled' => true]);
        $room = RoomType::factory()->create(['listing_id' => $listing->id, 'total_units' => 2, 'currency' => 'NAD']);

        // How a demo property seeds twelve weeks of history without posting
        // several hundred confirmations about guests who do not exist.
        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($room, 1, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-11'))],
            guestName: 'Seeded',
            guestEmail: 'seeded@example.test',
            source: ReservationSource::Website,
            notify: false,
        ));

        Mail::assertNothingSent();
    }

    public function test_the_notice_says_what_is_happening_and_stops_when_it_stops(): void
    {
        $notLive = $this->property([], 'camp-one');
        $this->assertStringContainsString('not live yet', (new BookingMailbox($notLive))->holdingNotice() ?? '');

        $testing = $this->property(['booking_demo_mode' => true, 'booking_demo_email' => 'ops@okonjima.test'], 'camp-two');
        $this->assertStringContainsString('ops@okonjima.test', (new BookingMailbox($testing))->holdingNotice() ?? '');

        $live = $this->property(['booking_enabled' => true], 'camp-three');
        $this->assertNull((new BookingMailbox($live))->holdingNotice());
    }

    public function test_the_job_is_what_the_writer_dispatches(): void
    {
        Queue::fake();

        $listing = $this->property();
        $this->book($listing);

        Queue::assertPushed(
            NotifyAboutStay::class,
            fn (NotifyAboutStay $job) => $job->event === NotifyAboutStay::BOOKED,
        );
    }
}
