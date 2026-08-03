<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Services\Booking\InquiryDecisionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PartnerController extends Controller
{
    public function __construct(private readonly InquiryDecisionService $decisions) {}

    public function confirm(Inquiry $inquiry): Response|RedirectResponse
    {
        if (! $this->decisions->confirm($inquiry)) {
            return Inertia::render('partner/ActionResult', [
                'outcome' => 'already_handled',
                'message' => 'This booking has already been processed.',
            ]);
        }

        return Inertia::render('partner/ActionResult', [
            'outcome' => 'confirmed',
            'message' => 'Booking confirmed. The guest has been notified.',
            'inquiry' => [
                'id' => $inquiry->id,
                'guest_name' => $inquiry->name,
                'listing_name' => $inquiry->listing->name,
                'check_in' => $inquiry->check_in?->toDateString(),
                'check_out' => $inquiry->check_out?->toDateString(),
                'connector_reference' => $inquiry->connector_reference,
            ],
        ]);
    }

    public function cancel(Inquiry $inquiry): Response|RedirectResponse
    {
        if (! $this->decisions->decline($inquiry)) {
            return Inertia::render('partner/ActionResult', [
                'outcome' => 'already_handled',
                'message' => 'This booking has already been processed.',
            ]);
        }

        return Inertia::render('partner/ActionResult', [
            'outcome' => 'cancelled',
            'message' => 'Booking declined. The request has been cancelled.',
            'inquiry' => [
                'id' => $inquiry->id,
                'guest_name' => $inquiry->name,
                'listing_name' => $inquiry->listing->name,
            ],
        ]);
    }
}
