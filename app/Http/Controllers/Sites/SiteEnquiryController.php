<?php

namespace App\Http\Controllers\Sites;

use App\Mail\EnquiryCopy;
use App\Models\Inquiry;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Services\Booking\SiteOrder;
use App\Sites\Blocks\EnquiryBlock;
use App\Sites\Blocks\EnquiryFormType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * An enquiry sent from a customer's own website.
 *
 * It creates the same `Inquiry` the travel platform's form creates, against the
 * listing behind the site — so it arrives where every other request arrives:
 * the partner gets the mail with the signed confirm and decline links
 * (routes/partner.php), and the guest gets a confirmation or a refusal
 * depending on which one is pressed. None of that is built here. This is a
 * second front door onto a pipeline that already works.
 *
 * ## No account, and no one-active-request gate
 *
 * Both apply to namibway.com and neither applies here, deliberately. The gate
 * exists to stop one traveller putting the same speculative request to twenty
 * lodges at once; somebody writing to the single business whose website they
 * are reading is not that, and asking them to register first would lose the
 * enquiry — which is the only thing this page is for.
 *
 * ## No CSRF token
 *
 * These pages carry no session, which is most of the reason they are small. A
 * token would mean issuing a cookie to every reader of a public marketing page
 * to protect an action that is neither authenticated nor destructive: the worst
 * a forged request achieves is an enquiry the business ignores. A rate limit and
 * a honeypot are the proportionate guards, and they are on the route.
 *
 * ## Why validation does not redirect back with errors
 *
 * `withErrors()` flashes to the session, and there isn't one. So the answer is
 * carried in the query string instead: `?sent=1` for the thank-you, `?sent=0`
 * for "something was incomplete". The browser has already enforced required,
 * email and date on the way out, so a failure here is a bot or a hand-rolled
 * POST — neither of which needs field-level messages.
 */
class SiteEnquiryController
{
    public function __invoke(Request $request, Site $site): RedirectResponse
    {
        $listing = $site->sourceListing;
        $partner = $site->partner;

        // A site must belong to somebody. A listing where there is one, the
        // partner otherwise — a shop that never listed on the travel platform
        // is asked for goods through its own website and has no listing at all,
        // which used to be answered with a 404 for every enquiry it received.
        abort_if($listing === null && $partner === null, 404);

        // The type is the block's, never the browser's: the posted field only
        // says which form the visitor was looking at, and a page that claims a
        // type this site does not offer is answered with the one it does.
        $type = $this->formType($site, $request);

        // A filled honeypot is answered exactly like a success, so the machine
        // that filled it learns nothing and stops retrying. Nothing is written.
        if (filled($request->input('website'))) {
            return $this->back($request, true);
        }

        $validator = Validator::make($request->all(), $this->rules($type));

        if ($validator->fails()) {
            return $this->back($request, false);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        // Priced before anything is written, so an order naming something that
        // is no longer for sale fails as a rejected form rather than as a
        // request the business cannot fill.
        $order = $type->hasItems()
            ? SiteOrder::price($site, $type, (array) ($validated['items'] ?? []))
            : null;

        if ($order !== null && $order->isEmpty()) {
            return $this->back($request, false);
        }

        $inquiry = Inquiry::create([
            'listing_id' => $listing?->id,
            // Filled on every row, so "which business is this for?" is one
            // column rather than a join through a listing that may be absent.
            'partner_id' => $site->partner_id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'kind' => $type->inquiryKind(),
            'check_in' => $validated['check_in'] ?? null,
            'check_out' => $validated['check_out'] ?? null,
            // A table is a date and a time with no departure. The time used to
            // be folded into the free-text travel_dates here to avoid a
            // migration; namibway.com's own restaurant form is a second front
            // door onto the same fact, so it has a column of its own now.
            'arrival_time' => $validated['time'] ?? null,
            'travel_dates' => $this->when($validated),
            // Both columns are NOT NULL with a default; passing null explicitly
            // overrides the default and fails, so the fallback lives here.
            'adults' => $validated['adults'] ?? 2,
            'children' => $validated['children'] ?? 0,
            // The delivery address rides in the message rather than in a column
            // of its own: it is one line of prose the business reads, not a
            // field anything queries or ships against yet.
            'message' => $this->message($validated),
        ]);

        $order?->attachTo($inquiry, $type);

        // The visitor's own receipt, at once. The business has not answered yet,
        // so this is deliberately not a confirmation — but somebody who filled
        // in a form on a small lodge's website should not be left with nothing
        // to show for it, and nothing to chase.
        //
        // Guarded, because the enquiry is already written by this point: a queue
        // that is down must not turn a recorded enquiry into an error page and
        // send the visitor away thinking it failed. Found the honest way — the
        // local Redis was off and this returned a 500 over a row that had
        // committed perfectly well.
        try {
            Mail::to($validated['email'])->send(new EnquiryCopy($inquiry));
        } catch (Throwable $e) {
            report($e);
        }

        // Back to the form, which then says thank you. A redirect rather than a
        // rendered response so a refresh cannot send it twice.
        return $this->back($request, true);
    }

    /**
     * Back to the page that sent this, with the outcome in the query string.
     *
     * The referer is rebuilt rather than concatenated: a draft is read at
     * `?preview=<token>`, and appending `?sent=1` to that produces a second
     * question mark and loses the token.
     */
    private function back(Request $request, bool $sent): RedirectResponse
    {
        $referer = (string) $request->headers->get('referer', '');
        $parts = parse_url($referer);

        // Only ever back to this application — a referer is whatever the client
        // says it is, and an open redirect is not worth a convenience.
        if ($parts === false || ! isset($parts['host']) || $parts['host'] !== $request->getHost()) {
            $parts = ['path' => '/'];
        }

        parse_str($parts['query'] ?? '', $query);
        $query['sent'] = $sent ? '1' : '0';

        return redirect()->to(
            ($parts['path'] ?? '/').'?'.http_build_query($query).'#enquiry'
        );
    }

    /**
     * Which form this site shows.
     *
     * Read from the block, not from the POST. The hidden field in the form only
     * records what the visitor was looking at; a page left open while the owner
     * changed the type, or a hand-rolled request naming something else, both
     * get the type the site actually offers.
     */
    private function formType(Site $site, Request $request): EnquiryFormType
    {
        $block = $site->pages()
            ->with('blocks')
            ->get()
            ->flatMap(fn ($page) => $page->blocks)
            ->first(fn (SiteBlock $block): bool => $block->type === 'enquiry');

        return $block === null
            ? EnquiryFormType::Contact
            : EnquiryBlock::formType($block->data);
    }

    /**
     * What each form requires, and what it refuses.
     *
     * Same discipline as ListingController on namibway.com: the fields follow
     * the shape of the request, so an order never carries a date nobody asked
     * for and a contact message is never rejected for missing one.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(EnquiryFormType $type): array
    {
        $contact = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];

        return match ($type) {
            EnquiryFormType::Contact => $contact,

            EnquiryFormType::StayRequest => [
                ...$contact,
                'check_in' => ['required', 'date', 'after_or_equal:today'],
                'check_out' => ['required', 'date', 'after:check_in'],
                'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
                'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            ],

            EnquiryFormType::TableReservation => [
                ...$contact,
                'check_in' => ['required', 'date', 'after_or_equal:today'],
                'time' => ['required', 'date_format:H:i'],
                'adults' => ['required', 'integer', 'min:1', 'max:20'],
                'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            ],

            EnquiryFormType::RestaurantOrder => [
                ...$contact,
                'items' => ['required', 'array', 'min:1', 'max:60'],
                'items.*' => ['integer', 'min:0', 'max:99'],
            ],

            EnquiryFormType::ProductOrder => [
                ...$contact,
                'items' => ['required', 'array', 'min:1', 'max:60'],
                'items.*' => ['integer', 'min:0', 'max:99'],
                'address' => ['required', 'string', 'max:500'],
            ],
        };
    }

    /**
     * The human-readable summary of when, for the partner screens that show
     * this column. Empty for anything with no dates at all.
     *
     * @param  array<string, mixed>  $validated
     */
    private function when(array $validated): ?string
    {
        $date = $validated['check_in'] ?? null;

        if (blank($date)) {
            return null;
        }

        return filled($validated['check_out'] ?? null)
            ? $date.' – '.$validated['check_out']
            : (string) $date;
    }

    /**
     * The visitor's message, with the delivery address in front of it where the
     * form collected one.
     *
     * @param  array<string, mixed>  $validated
     */
    private function message(array $validated): ?string
    {
        $message = $validated['message'] ?? null;
        $address = $validated['address'] ?? null;

        if (blank($address)) {
            return $message;
        }

        return blank($message)
            ? "Delivery address:\n".$address
            : "Delivery address:\n".$address."\n\n".$message;
    }
}
