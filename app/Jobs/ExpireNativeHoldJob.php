<?php

namespace App\Jobs;

use App\Enums\InquiryStatus;
use App\Enums\StayStatus;
use App\Mail\BookingHoldExpired;
use App\Models\Inquiry;
use App\Models\Reservation;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Releases a Native booking's soft hold if the partner hasn't confirmed by
 * hold_expires_at (see NativeConnector::createReservation, which dispatches
 * this delayed by the hold duration). Idempotent: if the partner already
 * confirmed or cancelled, the status guard below makes this a no-op — no
 * need to track or cancel the queued job itself.
 */
class ExpireNativeHoldJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $inquiryId) {}

    /**
     * Give back the provisional stay this request was holding, if it took one.
     * Guarded on the status so a stay somebody has already confirmed or
     * cancelled is left exactly as it is.
     */
    private function release(Inquiry $inquiry): void
    {
        $held = Reservation::query()
            ->where('inquiry_id', $inquiry->id)
            ->where('status', StayStatus::Provisional)
            ->first();

        if ($held !== null) {
            app(InventoryWriter::class)->cancel($held, 'Hold expired');
        }
    }

    public function handle(): void
    {
        $inquiry = Inquiry::find($this->inquiryId);

        if (! $inquiry
            || $inquiry->status !== InquiryStatus::OnRequest
            || ! $inquiry->hold_expires_at
            || $inquiry->hold_expires_at->isFuture()) {
            return;
        }

        $inquiry->update([
            'status' => InquiryStatus::Cancelled,
            'notes' => 'Hold expired — partner did not confirm in time',
        ]);

        // And the room goes back on sale. A hold that expires without
        // releasing its inventory is worse than no hold at all: the calendar
        // would carry a stay nobody is coming for, and the property would look
        // full for the rest of the season.
        $this->release($inquiry);

        Mail::to($inquiry->email)->send(new BookingHoldExpired($inquiry));
    }
}
