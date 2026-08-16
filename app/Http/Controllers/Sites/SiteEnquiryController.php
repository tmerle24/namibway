<?php

namespace App\Http\Controllers\Sites;

use App\Enums\InquiryKind;
use App\Enums\ListingType;
use App\Mail\EnquiryCopy;
use App\Models\Listing;
use App\Models\Site;
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

        abort_if($listing === null, 404);

        // A filled honeypot is answered exactly like a success, so the machine
        // that filled it learns nothing and stops retrying. Nothing is written.
        if (filled($request->input('website'))) {
            return $this->back($request, true);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['nullable', 'date', 'after:check_in'],
            'time' => ['nullable', 'date_format:H:i'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->back($request, false);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        $inquiry = $listing->inquiries()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            // A visit is a date and a time with no departure. The time used to
            // be folded into the free-text travel_dates here to avoid a
            // migration; namibway.com's own restaurant form is a second front
            // door onto the same fact, so it has a column of its own now
            // (`inquiries.arrival_time`) and the emails render it from there.
            'kind' => $this->kind($listing, $validated),
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'] ?? null,
            'arrival_time' => $validated['time'] ?? null,
            'travel_dates' => $this->when($validated),
            // Both columns are NOT NULL with a default; passing null explicitly
            // overrides the default and fails, so the fallback lives here.
            'adults' => $validated['adults'] ?? 2,
            'children' => $validated['children'] ?? 0,
            'message' => $validated['message'] ?? null,
        ]);

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
     * What shape of request this is.
     *
     * The block already draws itself two ways — arrival and departure for a
     * stay, date and time for a visit (`App\Sites\Blocks\EnquiryBlock`) — and
     * this is the same distinction reaching the row it writes. A restaurant's
     * visit is a table booking; an activity's is still a booking request, which
     * is what it was before this column existed.
     *
     * A site's own page never takes an order: it has no menu of ours to order
     * from, and the enquiry block is one form, not a basket.
     *
     * @param  array<string, mixed>  $validated
     */
    private function kind(Listing $listing, array $validated): InquiryKind
    {
        return $listing->type === ListingType::Restaurant && filled($validated['time'] ?? null)
            ? InquiryKind::TableReservation
            : InquiryKind::Booking;
    }

    /**
     * The human-readable summary of when, for the partner screens that show
     * this column. The time is no longer appended: it has its own column, and
     * two copies of one fact is one too many.
     *
     * @param  array<string, mixed>  $validated
     */
    private function when(array $validated): string
    {
        $date = (string) $validated['check_in'];

        return filled($validated['check_out'] ?? null)
            ? $date.' – '.$validated['check_out']
            : $date;
    }
}
