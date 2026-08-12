<?php

namespace App\Http\Controllers;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Services\Booking\InquiryDecisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'inquiry' => $this->summarise($inquiry),
        ]);
    }

    /**
     * The step between "Confirm and ask for payment" in the email and it
     * happening: the request as it stands, and a box for anything the property
     * wants to say to the guest.
     *
     * The link in the email does not act on its own, deliberately. A mail
     * client that prefetches links would otherwise confirm bookings by itself,
     * and this is the one of the three buttons that also takes money.
     */
    public function showConfirmWithPayment(Request $request, Inquiry $inquiry): Response
    {
        return Inertia::render('partner/ConfirmWithPayment', [
            'action' => $request->fullUrl(),
            'handled' => $inquiry->status !== InquiryStatus::OnRequest,
            'inquiry' => $this->summarise($inquiry),
        ]);
    }

    public function confirmWithPayment(Request $request, Inquiry $inquiry): Response
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $message = trim((string) ($validated['message'] ?? ''));

        $outcome = $this->decisions->confirmAndRequestDeposit($inquiry, $message === '' ? null : $message);

        if (! $outcome->handled) {
            return Inertia::render('partner/ActionResult', [
                'outcome' => 'already_handled',
                'message' => 'This booking has already been processed.',
            ]);
        }

        return Inertia::render('partner/ActionResult', [
            'outcome' => 'confirmed',
            'message' => $outcome->askedForPayment()
                ? 'Booking confirmed. The guest has been sent the confirmation with your payment link.'
                : ($outcome->problem ?? 'Booking confirmed. The guest has been notified.'),
            'inquiry' => $this->summarise($inquiry->refresh()),
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
            'inquiry' => $this->summarise($inquiry),
        ]);
    }

    /**
     * What these pages show about a request. Nothing here is secret to the
     * partner, but it is a page reached by a link in an email, so it stays the
     * request and never the guest's account.
     *
     * @return array<string, mixed>
     */
    private function summarise(Inquiry $inquiry): array
    {
        return [
            'id' => $inquiry->id,
            'guest_name' => $inquiry->name,
            'listing_name' => $inquiry->listing->name,
            'check_in' => $inquiry->check_in?->toDateString(),
            'check_out' => $inquiry->check_out?->toDateString(),
            'connector_reference' => $inquiry->connector_reference,
        ];
    }
}
