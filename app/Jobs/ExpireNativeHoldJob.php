<?php

namespace App\Jobs;

use App\Enums\InquiryStatus;
use App\Mail\BookingHoldExpired;
use App\Models\Inquiry;
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

        Mail::to($inquiry->email)->send(new BookingHoldExpired($inquiry));
    }
}
