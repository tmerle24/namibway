<?php

namespace App\Jobs;

use App\Mail\GuestStayCancelled;
use App\Mail\GuestStayConfirmed;
use App\Mail\PartnerStayRecorded;
use App\Models\Reservation;
use App\Services\Booking\BookingMailbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Tell the people a booking concerns that it happened.
 *
 * Queued, and dispatched after the write has committed — a stay is not
 * cancelled because a mail server was slow, and a confirmation for a booking
 * that rolled back is worse than no confirmation at all.
 *
 * Every address comes from BookingMailbox, which is the one place that decides
 * whether a property is live. Nothing here knows about demo modes or holding
 * addresses, and nothing here should learn.
 */
class NotifyAboutStay implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const BOOKED = 'booked';

    public const CANCELLED = 'cancelled';

    public function __construct(
        public readonly Reservation $reservation,
        public readonly string $event = self::BOOKED,
    ) {}

    public function handle(): void
    {
        $reservation = $this->reservation->loadMissing(['listing.partner', 'units.bookableUnit']);
        $mailbox = BookingMailbox::for($reservation);

        if ($this->event === self::CANCELLED) {
            Mail::to($mailbox->guest($reservation->guest_email))
                ->send(new GuestStayCancelled($reservation));

            return;
        }

        // A stay with no guest address still notifies the property: the desk
        // took a telephone booking and needs its copy either way.
        if (filled($reservation->guest_email) || ! $mailbox->isLive()) {
            Mail::to($mailbox->guest($reservation->guest_email))
                ->send(new GuestStayConfirmed($reservation));
        }

        Mail::to($mailbox->property())
            ->send(new PartnerStayRecorded($reservation, $mailbox->holdingNotice()));
    }
}
