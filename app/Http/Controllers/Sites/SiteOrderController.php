<?php

namespace App\Http\Controllers\Sites;

use App\Enums\InquiryKind;
use App\Mail\EnquiryCopy;
use App\Mail\TraderOrderReceived;
use App\Models\Inquiry;
use App\Models\MenuItem;
use App\Models\Site;
use App\Services\Booking\MenuOrder;
use App\Services\Booking\SiteOrder;
use App\Sites\Blocks\EnquiryFormType;
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
 * Standalone order flow for small traders and restaurants.
 *
 * Reached via QR code → /order on the trader's own site host, or the /_sites
 * back door for development. Two item sources are supported:
 *
 * - **Shop products** (`ShopProduct`) — retail traders, craft sellers. Items
 *   belong to the Site directly, priced via `SiteOrder`.
 * - **Menu items** (`MenuItem`) — restaurants whose site links to a Listing.
 *   Items belong to the Listing, priced via `MenuOrder`. Shop products take
 *   priority when both exist.
 *
 * No session, no CSRF, no authentication — same posture as the rest of the
 * customer-website renderer. The honeypot and rate limit are the guards.
 */
class SiteOrderController
{
    /**
     * The standalone order form — what the QR code lands on.
     *
     * Shows all orderable items with inline quantity steppers. Shop products
     * are tried first; if none, the linked listing's menu items are loaded.
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

        // Restaurants have no shop products — fall back to the listing menu.
        $menuItems = collect();
        if ($products->isEmpty() && $site->sourceListing !== null) {
            $menuItems = $site->sourceListing->menuItems()
                ->available()
                ->orderBy('category')
                ->orderBy('sort')
                ->orderBy('name')
                ->get();
        }

        $orderAction = $this->actionUrl($request, 'order');

        return response()->view('sites.order', [
            'site' => $site,
            'products' => $products,
            'menuItems' => $menuItems,
            'isRestaurant' => $menuItems->isNotEmpty(),
            'contactMode' => $this->contactMode($site),
            'accent' => $this->accent($site),
            'orderAction' => $orderAction,
        ]);
    }

    /**
     * Order form submission.
     *
     * Detects whether items are shop products or menu items (server-side, not
     * from the POST), prices them via the appropriate service, creates the
     * Inquiry, and hands off to the payment flow.
     */
    public function submit(Request $request, Site $site): RedirectResponse
    {
        abort_if($site->partner === null, 404);

        if (filled($request->input('website'))) {
            return $this->redirectTo($request, 'order/thanks');
        }

        $phoneMode = $this->contactMode($site) === 'phone';

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => $phoneMode ? ['nullable', 'email', 'max:255'] : ['required', 'email', 'max:255'],
            'phone' => $phoneMode ? ['required', 'string', 'max:50'] : ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*' => ['integer', 'min:0', 'max:99'],
            'payment' => ['required', 'in:cash,simulated'],
        ]);

        if ($validator->fails()) {
            return redirect()->away($this->actionUrl($request, 'order').'?error=1');
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        $listing = $site->sourceListing;
        $hasShopProducts = $site->shopProducts()->published()->exists();

        if (! $hasShopProducts && $listing !== null) {
            // Restaurant path — price from menu_items via MenuOrder.
            $lines = $this->itemsToMenuLines((array) $validated['items']);

            try {
                $menuOrder = MenuOrder::price($listing, $lines);
            } catch (Throwable) {
                return redirect()->away($this->actionUrl($request, 'order').'?error=1');
            }

            $inquiry = Inquiry::create([
                'listing_id' => $listing->id,
                'partner_id' => $site->partner_id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'kind' => InquiryKind::Order,
                'adults' => 1,
                'children' => 0,
                'message' => $validated['message'] ?? null,
                'payment_state' => $validated['payment'] === 'cash' ? 'awaiting_cash' : null,
            ]);

            $menuOrder->attachTo($inquiry);
        } else {
            // Shop path — price from shop_products via SiteOrder.
            $shopOrder = SiteOrder::price($site, EnquiryFormType::ProductOrder, (array) $validated['items']);

            if ($shopOrder->isEmpty()) {
                return redirect()->away($this->actionUrl($request, 'order').'?error=1');
            }

            $inquiry = Inquiry::create([
                'listing_id' => $site->sourceListing?->id,
                'partner_id' => $site->partner_id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'kind' => InquiryKind::Order,
                'adults' => 1,
                'children' => 0,
                'message' => $validated['message'] ?? null,
                'payment_state' => $validated['payment'] === 'cash' ? 'awaiting_cash' : null,
            ]);

            $shopOrder->attachTo($inquiry, EnquiryFormType::ProductOrder);
        }

        // EnquiryCopy requires an email address — skip in phone mode.
        if (filled($inquiry->email)) {
            try {
                Mail::to($inquiry->email)->send(new EnquiryCopy($inquiry));
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($validated['payment'] === 'cash') {
            $this->notifyTrader($site, $inquiry);

            return $this->redirectTo($request, 'order/thanks');
        }

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
            'site' => $site,
            'inquiry' => $inquiry,
            'accent' => $this->accent($site),
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
            'site' => $site,
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

        // Draft sites require ?preview= on every page — without it assertVisible()
        // aborts with 404, making the printed QR useless before the site goes live.
        // Append the draft_token so it remains scannable during review; published
        // sites get a clean URL with no token.
        if (! $site->isPublished()) {
            $url .= '?preview='.$site->draft_token;
        }

        $result = (new Builder(
            writer: new PngWriter,
            data: $url,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 400,
            margin: 20,
        ))->build();

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
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
     * Convert the flat `items[{id}] = qty` post format to the array-of-lines
     * format that `MenuOrder::price()` expects.
     *
     * @param  array<string, mixed>  $items
     * @return array<int, array{menu_item_id: int, quantity: int}>
     */
    private function itemsToMenuLines(array $items): array
    {
        $lines = [];

        foreach ($items as $id => $qty) {
            $id = (int) $id;
            $qty = (int) $qty;

            if ($id > 0 && $qty > 0) {
                $lines[] = ['menu_item_id' => $id, 'quantity' => $qty];
            }
        }

        return $lines;
    }

    private function redirectTo(Request $request, string $path): RedirectResponse
    {
        return redirect()->away($this->siteBase($request).'/'.$path);
    }

    private function actionUrl(Request $request, string $path): string
    {
        return $this->siteBase($request).'/'.$path;
    }

    private function siteBase(Request $request): string
    {
        $url = $request->url();

        return rtrim(Str::before($url, '/order'), '/');
    }

    /**
     * 'phone' when the trader configured WhatsApp (the customer's phone number
     * is the natural reply channel); 'email' otherwise. Never both — the form
     * shows exactly one contact field beyond name so the flow stays frictionless.
     */
    private function contactMode(Site $site): string
    {
        return filled($site->whatsapp) ? 'phone' : 'email';
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
