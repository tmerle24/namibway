<?php

namespace App\Http\Controllers\Sites;

use App\Enums\InquiryKind;
use App\Mail\EnquiryCopy;
use App\Mail\TraderOrderReceived;
use App\Models\Inquiry;
use App\Models\Site;
use App\Services\Booking\SiteOrder;
use App\Sites\Blocks\EnquiryFormType;
use App\Sites\Rendering\EnquiryItems;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Standalone order flow for small traders.
 *
 * Reached via QR code → /order on the trader's own site host, or the /_sites
 * back door for development. The form is always product_order — a pickup-first,
 * cash-or-simulated-payment prototype wiring the pipeline end to end before
 * WhatsApp and real payment links are added.
 *
 * No session, no CSRF, no authentication — same posture as the rest of the
 * customer-website renderer. The honeypot and rate limit are the guards.
 */
class SiteOrderController
{
    /**
     * The standalone order form — what the QR code lands on.
     *
     * Shows all orderable products for this site with inline quantity steppers
     * (no drawer — picking items is the entire point of the page, so the drawer
     * is friction, not UX). Contact fields and payment choice follow.
     */
    public function form(Request $request, Site $site): Response
    {
        abort_if($site->partner === null, 404);

        $products = $site->shopProducts()
            ->with('images')
            ->published()
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        abort_if($products->isEmpty(), 404);

        $orderAction = $this->actionUrl($request, 'order');

        return response()->view('sites.order', [
            'site'        => $site,
            'products'    => $products,
            'accent'      => $this->accent($site),
            'orderAction' => $orderAction,
        ]);
    }

    /**
     * Order form submission.
     *
     * Creates the Inquiry and either redirects to the payment simulation page
     * (simulated_payment) or marks it as awaiting cash and sends the trader
     * email immediately.
     */
    public function submit(Request $request, Site $site): RedirectResponse
    {
        abort_if($site->partner === null, 404);

        if (filled($request->input('website'))) {
            return $this->redirectTo($request, 'order/thanks');
        }

        $validator = Validator::make($request->all(), [
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:2000'],
            'items'   => ['required', 'array', 'min:1', 'max:60'],
            'items.*' => ['integer', 'min:0', 'max:99'],
            'payment' => ['required', 'in:cash,simulated'],
        ]);

        if ($validator->fails()) {
            return redirect()->away($this->actionUrl($request, 'order').'?error=1');
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        $order = SiteOrder::price($site, EnquiryFormType::ProductOrder, (array) $validated['items']);

        if ($order->isEmpty()) {
            return redirect()->away($this->actionUrl($request, 'order').'?error=1');
        }

        $inquiry = Inquiry::create([
            'listing_id'    => $site->sourceListing?->id,
            'partner_id'    => $site->partner_id,
            'name'          => $validated['name'],
            'email'         => $validated['email'],
            'phone'         => $validated['phone'] ?? null,
            'kind'          => InquiryKind::Order,
            'adults'        => 1,
            'children'      => 0,
            'message'       => $validated['message'] ?? null,
            'payment_state' => $validated['payment'] === 'cash' ? 'awaiting_cash' : null,
        ]);

        $order->attachTo($inquiry, EnquiryFormType::ProductOrder);

        // Customer copy — same as the enquiry form sends.
        try {
            Mail::to($validated['email'])->send(new EnquiryCopy($inquiry));
        } catch (Throwable $e) {
            report($e);
        }

        if ($validated['payment'] === 'cash') {
            $this->notifyTrader($site, $inquiry);

            return $this->redirectTo($request, 'order/thanks');
        }

        // Simulated: hand off to the payment page before the trader is notified.
        return $this->redirectTo($request, 'order/pay/'.$inquiry->id);
    }

    /**
     * The payment simulation page.
     *
     * Shows the order summary and a single "Confirm payment (demo)" button.
     * No real payment occurs. The session-less design means the inquiry id in
     * the URL is the only state carrier; the controller re-validates it against
     * the site to prevent an id from one site's order appearing on another's.
     */
    public function payPage(Request $request, Site $site, Inquiry $inquiry): Response
    {
        abort_unless($inquiry->partner_id === $site->partner_id, 404);
        abort_unless($inquiry->payment_state === null, 404);

        return response()->view('sites.order-pay', [
            'site'        => $site,
            'inquiry'     => $inquiry,
            'accent'      => $this->accent($site),
            'confirmAction' => $this->actionUrl($request, 'order/pay/'.$inquiry->id),
        ]);
    }

    /**
     * The customer presses "Confirm payment (demo)".
     *
     * Marks the inquiry as paid (simulation), notifies the trader, redirects
     * to the shared thank-you page. Idempotent: a second press on the same
     * inquiry is answered with a 404 from payPage's guard.
     */
    public function payConfirm(Request $request, Site $site, Inquiry $inquiry): RedirectResponse
    {
        abort_unless($inquiry->partner_id === $site->partner_id, 404);
        abort_unless($inquiry->payment_state === null, 404);

        $inquiry->update(['payment_state' => 'simulated_paid']);

        $this->notifyTrader($site, $inquiry);

        return $this->redirectTo($request, 'order/thanks');
    }

    /**
     * The thank-you page, shown after both payment paths complete.
     */
    public function thanks(Request $request, Site $site): Response
    {
        return response()->view('sites.order-thanks', [
            'site'   => $site,
            'accent' => $this->accent($site),
        ]);
    }

    /**
     * Returns a QR code PNG pointing at this site's order form.
     *
     * The QR encodes the canonical URL — the site's own host when it has one,
     * otherwise the platform-path fallback. Either way the URL is stable once
     * printed (the spec requires stability because a reprint costs the trader).
     */
    public function qr(Request $request, Site $site): Response
    {
        abort_if($site->partner === null, 404);

        // For a site with its own domain, use the canonical URL — it is stable
        // once printed and must not change (reprinting costs the trader). For the
        // /_sites path fallback, derive from the current request so the QR is
        // scannable from whatever host the image itself was reached on: accessing
        // /order/qr via the machine's LAN IP produces a QR that a phone can reach.
        $url = filled($site->host)
            ? $site->pageUrl('order')
            : rtrim(Str::before($request->url(), '/order'), '/').'/order';

        $result = (new Builder(
            writer: new PngWriter(),
            data: $url,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 400,
            margin: 20,
        ))->build();

        return response($result->getString(), 200, [
            'Content-Type'  => $result->getMimeType(),
            'Cache-Control' => 'public, max-age=86400',
            'Content-Disposition' => 'inline; filename="order-qr.png"',
        ]);
    }

    private function notifyTrader(Site $site, Inquiry $inquiry): void
    {
        $email = $inquiry->sellerEmail();

        if (blank($email)) {
            return;
        }

        try {
            Mail::to($email)->send(new TraderOrderReceived($inquiry));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Redirect to a path relative to this site's root — works for both the host
     * path (https://myshop.namibway.com) and the dev path fallback
     * (https://namibway.com/_sites/my-shop).
     *
     * The submit URL is always …/order, so everything before that is the root.
     * For pay/confirm the submit URL is …/order/pay/{id}, so the root is before
     * /order/pay/. Both strip cleanly with a simple beforeLast.
     */
    private function redirectTo(Request $request, string $path): RedirectResponse
    {
        $base = $this->siteBase($request);

        return redirect()->away($base.'/'.$path);
    }

    /**
     * Absolute URL for a path under this site's root, computed from the current
     * request URL. Used for form actions and redirects so that the same
     * controller method works on both the site's own host and the dev fallback.
     */
    private function actionUrl(Request $request, string $path): string
    {
        return $this->siteBase($request).'/'.$path;
    }

    private function siteBase(Request $request): string
    {
        $url = $request->url();

        // Both submit URLs contain "/order"; everything before it is the root.
        return rtrim(Str::before($url, '/order'), '/');
    }

    private function accent(Site $site): string
    {
        /** @var array<string, string> $accents */
        $accents = (array) config('sites.accents', []);

        return $accents[$site->accent]
            ?? $accents[(string) config('sites.default_accent', 'copper')]
            ?? '#B87333';
    }
}
