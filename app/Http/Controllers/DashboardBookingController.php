<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Services\Booking\InquiryDecisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardBookingController extends Controller
{
    public function __construct(private readonly InquiryDecisionService $decisions) {}

    public function confirm(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->authorizeOwner($request, $inquiry);

        $this->decisions->confirm($inquiry);

        return back();
    }

    public function decline(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->authorizeOwner($request, $inquiry);

        $this->decisions->decline($inquiry);

        return back();
    }

    private function authorizeOwner(Request $request, Inquiry $inquiry): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->partner_id !== null && $inquiry->listing?->partner_id === $user->partner_id,
            403,
        );
    }
}
