<?php

namespace App\Http\Controllers\Sites;

use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

        abort_if($listing === null || ! $listing->accepts_inquiries, 404);

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

        $listing->inquiries()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'] ?? null,
            // An activity or a restaurant has a time and no departure, and
            // `inquiries` has no column for one. It goes in the free-text
            // travel_dates beside the date rather than into a migration, so the
            // partner reads it in the same line they already read.
            'travel_dates' => $this->when($validated),
            // Both columns are NOT NULL with a default; passing null explicitly
            // overrides the default and fails, so the fallback lives here.
            'adults' => $validated['adults'] ?? 2,
            'children' => $validated['children'] ?? 0,
            'message' => $validated['message'] ?? null,
        ]);

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
     * @param  array<string, mixed>  $validated
     */
    private function when(array $validated): string
    {
        $date = (string) $validated['check_in'];

        if (filled($validated['check_out'] ?? null)) {
            return $date.' – '.$validated['check_out'];
        }

        return filled($validated['time'] ?? null)
            ? $date.' at '.$validated['time']
            : $date;
    }
}
