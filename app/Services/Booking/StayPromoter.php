<?php

namespace App\Services\Booking;

use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Exceptions\Booking\StayNotPromotableException;
use App\Mail\StayPromotionFailed;
use App\Models\Inquiry;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The bridge: a confirmed request becomes a stay on the property's calendar.
 *
 * `Inquiry` and `Reservation` are not two names for one thing and are not
 * merged — CLAUDE.md, "How a confirmed Inquiry becomes a Reservation", holds
 * the reasoning. An inquiry is the traveller's *request*: it can be declined,
 * it can expire with its soft hold, and it is what the one-active-request gate
 * counts. A reservation is the property's *stay*: it holds inventory, it has a
 * lifecycle at a front desk, and it exists for walk-ins that were never
 * requested at all. So this is a one-way promotion, never a sync.
 *
 * ## When
 *
 * Twice, in two states. A request that is waiting for an answer takes a
 * **provisional** stay — the room is held, not promised — and confirming it
 * turns that same stay into a confirmed one. A request nobody held (a partner
 * on somebody else's PMS, a stay with no dates) becomes a confirmed stay
 * outright at the moment it is confirmed.
 *
 * A hold is not the flooding problem the booking mechanic guards against.
 * That problem is *partners* being asked to answer speculative requests, and
 * the one-active-request gate is what solves it. A room quietly held for a few
 * hours behind a request that already exists asks nobody for anything — and it
 * is the difference between selling the same room twice and not.
 *
 * ## Idempotency
 *
 * `reservations.inquiry_id` is unique, so a re-confirm, a retried job or a
 * partner clicking the signed link twice cannot produce a second stay. The
 * lookup below is a courtesy; the constraint is the mechanism.
 *
 * ## What may fail, and what happens then
 *
 * Promotion can fail where the request could not — much more rarely since the
 * room is now held while the partner decides, but a request that took no hold
 * still has a window in which its room can go. **A failed promotion never
 * un-confirms the guest's booking.** The guest has been told they have a room;
 * the honest consequence is an operational alert to the team, not a
 * cancellation.
 *
 * Restrictions are the one thing deliberately not enforced here. A minimum
 * stay or a closed-to-arrival day is a rule about *selling* a night. This
 * stay has already been sold and confirmed — recording it is not a second
 * sale, and refusing to write it down would leave the calendar lying about
 * how full the property is.
 */
class StayPromoter
{
    public function __construct(private readonly InventoryWriter $writer) {}

    /**
     * Hold the room while the partner decides.
     *
     * A request that is waiting for an answer is not a stay, but it is not
     * nothing either: the room it asks for should not be sold to somebody else
     * in the meantime. So it goes onto the calendar as a **provisional** stay —
     * real inventory, released by the same expiry that already governs the
     * hold, and turned into a confirmed stay by promote() if the partner says
     * yes.
     *
     * This is what makes the calendar the only counter. Until every request
     * held inventory, availability had to be the smaller of two numbers; a
     * request that holds its room is counted once, by the calendar, like
     * everything else.
     *
     * Quiet by design. A hold that cannot be taken — the last room went while
     * the request was in the post — leaves the request exactly as it was, and
     * RoomAvailability goes on counting it the old way. A hold is an
     * improvement on a request, never a precondition for one.
     */
    public function hold(Inquiry $inquiry): ?Reservation
    {
        $existing = Reservation::query()->where('inquiry_id', $inquiry->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->write($inquiry, StayStatus::Provisional);
        } catch (Throwable $failure) {
            Log::info("StayPromoter: no hold taken for inquiry [{$inquiry->id}]: ".$failure->getMessage());

            return null;
        }
    }

    /**
     * Record the stay, or say why it cannot be recorded. Called twice for the
     * same request, it returns the stay it made the first time.
     *
     * @throws StayNotPromotableException when the request cannot become a stay
     */
    public function promote(Inquiry $inquiry): Reservation
    {
        $existing = Reservation::query()->where('inquiry_id', $inquiry->id)->first();

        if ($existing !== null) {
            // The room was already held for this request. Confirming it is a
            // transition, not a second stay — the inventory it consumed at
            // hold time is the inventory the guest keeps.
            return $existing->status === StayStatus::Provisional
                ? $this->writer->transition($existing, StayStatus::Confirmed)
                : $existing;
        }

        return $this->write($inquiry, StayStatus::Confirmed);
    }

    /**
     * The stay itself, in whichever state it is being written.
     *
     * @throws StayNotPromotableException when the request cannot become a stay
     */
    private function write(Inquiry $inquiry, StayStatus $status): Reservation
    {
        $listing = $inquiry->listing;

        if ($listing === null) {
            throw StayNotPromotableException::withoutProperty($inquiry);
        }

        // A stay without dates cannot be allocated to nights. `travel_dates` is
        // free text and always has been, so this is a validation rule at
        // confirmation time rather than a guess at what "mid-June, 3 nights"
        // meant.
        if ($inquiry->check_in === null || $inquiry->check_out === null) {
            throw StayNotPromotableException::withoutDates($inquiry);
        }

        $roomType = $this->roomTypeFor($inquiry);

        return $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: [new BookingLine($roomType, 1, $inquiry->check_in->copy(), $inquiry->check_out->copy())],
            guestName: $inquiry->name,
            guestEmail: $inquiry->email,
            guestPhone: $inquiry->phone,
            source: ReservationSource::Website,
            status: $status,
            adults: max(1, $inquiry->adults),
            children: max(0, $inquiry->children),
            notes: $inquiry->message,
            inquiryId: $inquiry->id,
            // What the traveller was quoted, kept as what they are charged. The
            // price on a confirmed booking is settled — recomputing it here
            // could quietly change what somebody already agreed to pay.
            totalOverride: $inquiry->total_amount,
            overrideReason: $inquiry->total_amount === null ? null : 'Quoted on the website request',
            // The guest was written to by whoever confirmed the request, and
            // a hold is not news at all. A second confirmation for the same
            // booking reads as a second booking.
            notify: false,
            guestUserId: $inquiry->user_id,
            ignoreStayRules: true,
        ));
    }

    /**
     * Attempt the promotion and never let it break the confirmation it follows.
     *
     * Used by the two paths that confirm an inquiry. They have already told the
     * guest; a failure here is the team's problem to fix, not a reason to throw
     * out of a request the partner just approved.
     */
    public function promoteQuietly(Inquiry $inquiry): ?Reservation
    {
        try {
            return $this->promote($inquiry);
        } catch (Throwable $failure) {
            $this->alert($inquiry, $failure);

            return null;
        }
    }

    /**
     * The room this request is for.
     *
     * The code the traveller's picker recorded, where there is one. Where there
     * is not — most requests, because the picker only appears for properties
     * with room types entered — a property with exactly one active room type is
     * unambiguous and anything else is a guess, which is refused.
     */
    private function roomTypeFor(Inquiry $inquiry): RoomType
    {
        $rooms = RoomType::query()
            ->where('listing_id', $inquiry->listing_id)
            ->where('is_active', true)
            ->get();

        if (filled($inquiry->room_type_code)) {
            $matched = $rooms->firstWhere('code', $inquiry->room_type_code);

            if ($matched !== null) {
                return $matched;
            }
        }

        if ($rooms->count() === 1) {
            /** @var RoomType $only */
            $only = $rooms->first();

            return $only;
        }

        throw StayNotPromotableException::withoutRoomType($inquiry);
    }

    /**
     * Tell somebody. A stay that could not be written to the calendar is
     * invisible until a guest arrives for it, which is the worst way to find
     * out — so it goes to the log for the record and to the team mailbox for
     * somebody to act on today.
     */
    private function alert(Inquiry $inquiry, Throwable $failure): void
    {
        Log::error("StayPromoter: inquiry [{$inquiry->id}] confirmed but not recorded on the calendar: "
            .$failure->getMessage());

        try {
            Mail::to((string) config('booking.team_address', 'team@namibway.com'))
                ->send(new StayPromotionFailed($inquiry, $failure->getMessage()));
        } catch (Throwable $mailFailure) {
            // The log line above is the record either way. A mail server being
            // down must not turn one problem into two.
            Log::error('StayPromoter: could not send the alert: '.$mailFailure->getMessage());
        }
    }
}
