<?php

use App\Enums\InquiryStatus;
use App\Jobs\ExpireNativeHoldJob;
use App\Mail\BookingHoldExpired;
use App\Models\Inquiry;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('cancels an overdue on-request hold and notifies the guest', function () {
    Mail::fake();

    $listing = Listing::factory()->create();
    $inquiry = Inquiry::create([
        'listing_id' => $listing->id,
        'name' => 'Guest',
        'email' => 'guest@example.com',
        'status' => InquiryStatus::OnRequest,
        'hold_expires_at' => now()->subHour(),
    ]);

    (new ExpireNativeHoldJob($inquiry->id))->handle();

    expect($inquiry->fresh()->status)->toBe(InquiryStatus::Cancelled);
    Mail::assertQueued(BookingHoldExpired::class, fn ($mail) => $mail->inquiry->id === $inquiry->id);
});

it('leaves a hold untouched if it has not expired yet', function () {
    Mail::fake();

    $listing = Listing::factory()->create();
    $inquiry = Inquiry::create([
        'listing_id' => $listing->id,
        'name' => 'Guest',
        'email' => 'guest@example.com',
        'status' => InquiryStatus::OnRequest,
        'hold_expires_at' => now()->addHour(),
    ]);

    (new ExpireNativeHoldJob($inquiry->id))->handle();

    expect($inquiry->fresh()->status)->toBe(InquiryStatus::OnRequest);
    Mail::assertNothingQueued();
});

it('leaves an already-confirmed inquiry untouched', function () {
    Mail::fake();

    $listing = Listing::factory()->create();
    $inquiry = Inquiry::create([
        'listing_id' => $listing->id,
        'name' => 'Guest',
        'email' => 'guest@example.com',
        'status' => InquiryStatus::Confirmed,
        'hold_expires_at' => now()->subHour(),
    ]);

    (new ExpireNativeHoldJob($inquiry->id))->handle();

    expect($inquiry->fresh()->status)->toBe(InquiryStatus::Confirmed);
    Mail::assertNothingQueued();
});
