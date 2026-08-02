<?php

namespace App\Http\Controllers;

use App\Enums\ConnectorType;
use App\Enums\InquiryStatus;
use App\Jobs\EnrichListingJob;
use App\Mail\NewInquiryReceived;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Review;
use App\Services\Enrichment\OsmLocationFinder;
use App\Services\Region\PhoneNumberFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ListingController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = Listing::query()->where('is_published', true)->filterBy($request->query());

        $sort = $request->query('sort', 'featured');

        if ($sort === 'price_asc') {
            $query->orderBy('price_from');
        } elseif ($sort === 'price_desc') {
            $query->orderByDesc('price_from');
        } elseif ($sort === 'rating') {
            $query->orderByDesc('rating');
        } else {
            $query->orderByDesc('is_featured')->orderByDesc('rating');
        }

        $paginator = $query->paginate(12);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Listing $l) => [
                'id' => $l->id,
                'type' => $l->type->value,
                'name' => $l->name,
                'slug' => $l->slug,
                'description' => $l->description,
                'image' => $l->image ? self::resolveMediaUrl($l->image) : null,
                'region' => $l->region,
                'price_from' => $l->price_from,
                'price_currency' => $l->price_currency,
                'rating' => $l->rating !== null ? (float) $l->rating : null,
                'rating_count' => $l->rating_count,
                'accepts_inquiries' => $l->accepts_inquiries,
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function show(Request $request, Listing $listing): Response
    {
        $listing->load('partner');

        // Two ways to preview a draft before it's published: a logged-in admin (same
        // 'web' guard/User model the Filament admin panel authenticates with, so an
        // admin's session already carries over to this plain frontend route), or the
        // property owner via the same claim_token already emailed to them for the
        // claim flow — see ClaimInviteService, which links here with ?preview=<token>.
        $isAdmin = self::isAdmin();
        $isOwnerPreview = self::hasValidPreviewToken($listing, $request);
        $isPreview = ! $listing->is_published;

        abort_unless($listing->is_published || $isAdmin || $isOwnerPreview, 404);

        if ($listing->isDueForEnrichment()) {
            // Queued, not run inline — EnrichListingJob is ShouldBeUnique so a burst of
            // visits to the same stale listing only ever queues one enrichment run.
            EnrichListingJob::enqueue($listing->id);
        }

        $reviews = $listing->reviews()
            ->where('is_approved', true)
            ->latest()
            ->get(['id', 'name', 'rating', 'comment', 'created_at'])
            ->map(fn (Review $review) => [
                'id' => $review->id,
                'name' => $review->name,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at->toDateString(),
            ]);

        // Website-scraped photos the owner hasn't approved yet — only shown to the
        // owner themselves or an admin, never to the public, since we don't have the
        // right to publish them without consent (see Listing::approvePendingPhotos()).
        $canApprovePhotos = $isAdmin || $isOwnerPreview;

        // Some partner websites publish only GPS coordinates, not a street address —
        // the AI extractor then faithfully saves that coordinate text into `address`
        // (see AIListingExtractorService). Detect that case so the public page shows
        // a working map instead of a raw "S20°47.482 E016°42.704" string next to the
        // phone number.
        $coordinatesFromAddress = $listing->address ? self::parseCoordinateAddress($listing->address) : null;

        $phone = app(PhoneNumberFormatter::class)->format($listing->phone);

        return Inertia::render('ListingDetail', [
            'listing' => [
                'id' => $listing->id,
                'type' => $listing->type->value,
                'name' => $listing->name,
                'slug' => $listing->slug,
                'description' => $listing->description,
                'highlights' => $listing->highlights ?? [],
                'image' => $listing->image ? self::resolveMediaUrl($listing->image) : null,
                'gallery' => collect($listing->gallery ?? [])
                    ->map(fn (string $path) => self::resolveMediaUrl($path))
                    ->values(),
                'photos_source' => $listing->photos_source,
                'photos_attribution' => $listing->photos_attribution,
                'pending_image' => $canApprovePhotos && $listing->pending_image
                    ? self::resolveMediaUrl($listing->pending_image)
                    : null,
                'pending_gallery' => $canApprovePhotos
                    ? collect($listing->pending_gallery ?? [])->map(fn (string $path) => self::resolveMediaUrl($path))->values()
                    : [],
                'region' => $listing->region,
                'address' => $coordinatesFromAddress ? null : $listing->address,
                'phone' => $phone['display'] ?? null,
                'phone_href' => $phone['href'] ?? null,
                'website' => $listing->website,
                'latitude' => $listing->latitude !== null
                    ? (float) $listing->latitude
                    : $coordinatesFromAddress[0] ?? null,
                'longitude' => $listing->longitude !== null
                    ? (float) $listing->longitude
                    : $coordinatesFromAddress[1] ?? null,
                'price_from' => $listing->price_from,
                'price_currency' => $listing->price_currency,
                'rating' => $listing->rating !== null ? (float) $listing->rating : null,
                'rating_count' => $listing->rating_count,
                'accepts_inquiries' => $listing->accepts_inquiries,
                'contact_person' => $listing->contact_person,
                'partner' => $listing->partner ? [
                    'name' => $listing->partner->name,
                    'logo' => $listing->partner->logo ? self::resolveMediaUrl($listing->partner->logo) : null,
                    'website' => $listing->partner->website,
                    'instagram' => $listing->partner->instagram,
                    'facebook' => $listing->partner->facebook,
                ] : null,
            ],
            'reviews' => $reviews,
            'is_preview' => $isPreview,
            'can_publish' => $isAdmin || $isOwnerPreview,
            'can_approve_photos' => $canApprovePhotos,
            'preview_token' => $isOwnerPreview ? $request->input('preview') : null,
            'claim_url' => self::claimUrl($listing, $isOwnerPreview),
        ]);
    }

    /**
     * The property owner can do this themselves via their claim_token (see
     * hasValidPreviewToken()) — no account/login required. One click publishes the
     * listing and, if the pipeline staged any website-scraped photos awaiting consent,
     * approves those too: asking the owner to click twice (once to approve photos, once
     * to publish) is exactly the kind of extra friction that tanks completion rates.
     *
     * terms_accepted must be explicitly true — the frontend gates the actual publish
     * button behind a confirmation modal requiring it, so a request without it means
     * the button was bypassed rather than genuinely confirmed.
     */
    public function publish(Request $request, Listing $listing): RedirectResponse
    {
        $isAdmin = self::isAdmin();
        $isOwnerPreview = self::hasValidPreviewToken($listing, $request);

        abort_unless($isAdmin || $isOwnerPreview, 403);
        abort_unless($request->boolean('terms_accepted'), 422, 'Terms & Conditions must be accepted to publish.');

        $listing->approvePendingPhotos();
        $listing->update([
            'is_published' => true,
            'terms_accepted_at' => now(),
            'terms_accepted_by' => $isAdmin ? 'admin' : 'owner',
        ]);

        return redirect()->route('listings.show', array_filter([
            'listing' => $listing->slug,
            'preview' => $request->input('preview'),
        ]));
    }

    public function approvePhotos(Request $request, Listing $listing): RedirectResponse
    {
        abort_unless(self::isAdmin() || self::hasValidPreviewToken($listing, $request), 403);

        $listing->approvePendingPhotos();

        return redirect()->route('listings.show', array_filter([
            'listing' => $listing->slug,
            'preview' => $request->input('preview'),
        ]));
    }

    /**
     * Admin-only mirror of the "Enrich" row action on the Data Enrichment dashboard —
     * lets an admin trigger it right from the listing's own preview page instead of
     * navigating back to the table and searching for the row. Not extended to the
     * owner-preview token like publish/approve-photos: Google Places and Claude are
     * both metered/paid, so who gets to spend that money stays an admin decision.
     */
    public function enrich(Request $request, Listing $listing): RedirectResponse
    {
        abort_unless(self::isAdmin(), 403);

        $validated = $request->validate([
            'use_google_places' => ['nullable', 'boolean'],
            'use_claude' => ['nullable', 'boolean'],
        ]);

        $steps = ['website', 'scrape', 'images'];

        if (! empty($validated['use_claude'])) {
            $steps[] = 'ai_extract';
            $steps[] = 'description';
        }

        EnrichListingJob::enqueue($listing->id, $steps, (bool) ($validated['use_google_places'] ?? false));

        return back();
    }

    /**
     * Self-service editor for the property owner (via claim_token) or an admin — a
     * lighter-weight alternative to the Filament panel, which owners have no account
     * for. Covers the basics an owner would actually want to fix themselves: content,
     * photos, location, publish state, and which booking system this listing connects
     * to. Deliberately stops short of the full admin surface — no taxonomy, and no
     * booking-system API credentials (those stay a Filament-partner-portal/admin
     * concern, since they're account-level secrets shared across all of a partner's
     * listings, not something to collect over an emailed preview-token link).
     */
    public function edit(Request $request, Listing $listing): Response
    {
        abort_unless(self::isAdmin() || self::hasValidPreviewToken($listing, $request), 403);

        $listing->load('partner');

        $connectorType = $listing->partner?->connector_type;

        return Inertia::render('ListingEdit', [
            'listing' => [
                'id' => $listing->id,
                'slug' => $listing->slug,
                'name' => $listing->getTranslation('name', 'en', useFallbackLocale: false),
                'description' => $listing->getTranslation('description', 'en', useFallbackLocale: false),
                'short_description' => $listing->getTranslation('short_description', 'en', useFallbackLocale: false),
                'highlights' => $listing->getTranslation('highlights', 'en', useFallbackLocale: false) ?? [],
                'contact_person' => $listing->contact_person,
                'phone' => $listing->phone,
                'contact_email' => $listing->contact_email,
                'website' => $listing->website,
                'address' => $listing->address,
                'latitude' => $listing->latitude !== null ? (float) $listing->latitude : null,
                'longitude' => $listing->longitude !== null ? (float) $listing->longitude : null,
                'price_from' => $listing->price_from,
                'price_currency' => $listing->price_currency,
                'is_published' => $listing->is_published,
                'image' => $listing->image ? self::resolveMediaUrl($listing->image) : null,
                'gallery' => collect($listing->gallery ?? [])
                    ->map(fn (string $path) => self::resolveMediaUrl($path))
                    ->values(),
                'connector_type' => $connectorType?->value,
                'connector_property_code' => $listing->connector_property_code,
                'has_connector_credentials' => filled($listing->partner?->connector_config),
            ],
            'connector_options' => collect(ConnectorType::cases())
                ->map(fn (ConnectorType $c) => ['value' => $c->value, 'label' => $c->label()])
                ->values(),
            'preview_token' => self::hasValidPreviewToken($listing, $request) ? $request->input('preview') : null,
            'claim_url' => self::claimUrl($listing, self::hasValidPreviewToken($listing, $request)),
        ]);
    }

    public function update(Request $request, Listing $listing, OsmLocationFinder $osmFinder): RedirectResponse
    {
        $isAdmin = self::isAdmin();
        $isOwnerPreview = self::hasValidPreviewToken($listing, $request);

        abort_unless($isAdmin || $isOwnerPreview, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'highlights' => ['nullable', 'array'],
            'highlights.*' => ['string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'price_from' => ['nullable', 'numeric', 'min:0'],
            'price_currency' => ['nullable', 'string', 'max:3'],
            'image' => ['nullable', 'image', 'max:8192'],
            'remove_image' => ['nullable', 'boolean'],
            'gallery_touched' => ['nullable', 'boolean'],
            'gallery_keep' => ['nullable', 'array'],
            'gallery_keep.*' => ['string'],
            'gallery_new' => ['nullable', 'array', 'max:20'],
            'gallery_new.*' => ['image', 'max:8192'],
            'connector_type' => ['nullable', Rule::in(array_map(fn (ConnectorType $c) => $c->value, ConnectorType::cases()))],
            'connector_property_code' => ['nullable', 'string', 'max:100'],
            'publish' => ['nullable', 'boolean'],
            'unpublish' => ['nullable', 'boolean'],
            'terms_accepted' => ['nullable', 'boolean'],
            'preview' => ['nullable', 'string'],
        ]);

        // Same consent requirement as the dedicated publish() endpoint — the frontend's
        // "Save and publish" button is gated behind the same confirmation modal.
        abort_if(! empty($validated['publish']) && empty($validated['terms_accepted']), 422, 'Terms & Conditions must be accepted to publish.');

        $listing->setTranslation('name', 'en', $validated['name']);
        $listing->setTranslation('description', 'en', $validated['description'] ?? '');
        $listing->setTranslation('short_description', 'en', $validated['short_description'] ?? '');
        $listing->setTranslation('highlights', 'en', $validated['highlights'] ?? []);
        $validated = $osmFinder->fillMissingCoordinates($validated, $validated['name']);
        $listing->fill(array_filter([
            'contact_person' => $validated['contact_person'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'contact_email' => $validated['contact_email'] ?? null,
            'website' => $validated['website'] ?? null,
            'address' => $validated['address'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'price_from' => $validated['price_from'] ?? null,
            'price_currency' => $validated['price_currency'] ?? null,
            'connector_property_code' => $validated['connector_property_code'] ?? null,
        ], fn ($value) => $value !== null));

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('listings', 'public');
            $listing->image = $path ?: throw new RuntimeException('Failed to store hero image upload.');
        } elseif (! empty($validated['remove_image'])) {
            $listing->image = null;
        }

        // gallery_touched is a plain boolean flag (rather than inferring intent from
        // gallery_keep/gallery_new being present) because an emptied multipart array
        // sends no field at all — without the flag, "remove every photo and add none"
        // would be indistinguishable from "gallery untouched".
        if (! empty($validated['gallery_touched'])) {
            $keepUrls = $validated['gallery_keep'] ?? [];
            $kept = collect($listing->gallery ?? [])
                ->filter(fn (string $path) => in_array(self::resolveMediaUrl($path), $keepUrls, true));
            $newPaths = collect($request->file('gallery_new', []))
                ->map(fn ($file) => $file->store('listings/gallery', 'public') ?: throw new RuntimeException('Failed to store gallery image upload.'));

            $listing->gallery = $kept->concat($newPaths)->values()->all();
        }

        // connector_type is account-level (shared across all of this partner's
        // listings), unlike connector_property_code above which is per-listing — see
        // the migration that split them apart.
        if (array_key_exists('connector_type', $validated) && $listing->partner) {
            $listing->partner->update(['connector_type' => $validated['connector_type'] ?: null]);
        }

        if (! empty($validated['unpublish'])) {
            $listing->is_published = false;
        } elseif (! empty($validated['publish'])) {
            $listing->approvePendingPhotos();
            $listing->is_published = true;
            $listing->terms_accepted_at = now();
            $listing->terms_accepted_by = $isAdmin ? 'admin' : 'owner';
        }

        $listing->save();

        $redirectRoute = ! empty($validated['publish']) || $request->input('redirect') === 'preview'
            ? 'listings.show'
            : 'listings.edit';

        return redirect()->route($redirectRoute, array_filter([
            'listing' => $listing->slug,
            'preview' => $validated['preview'] ?? null,
        ]));
    }

    private static function isAdmin(): bool
    {
        return auth()->check() && auth()->user()->is_admin;
    }

    /**
     * Matches degrees-decimal-minutes GPS text like "S20°47.482 E016°42.704", the
     * format Namibian lodge sites tend to publish in place of a street address.
     *
     * @return array{0: float, 1: float}|null [latitude, longitude]
     */
    private static function parseCoordinateAddress(string $address): ?array
    {
        $pattern = '/^([NS])\s*(\d{1,2})°\s*(\d{1,2}(?:\.\d+)?)[\'′]?\s*([EW])\s*(\d{1,3})°\s*(\d{1,2}(?:\.\d+)?)[\'′]?/i';

        if (! preg_match($pattern, trim($address), $m)) {
            return null;
        }

        $latitude = ((float) $m[2]) + ((float) $m[3]) / 60;
        $longitude = ((float) $m[5]) + ((float) $m[6]) / 60;

        return [
            strtoupper($m[1]) === 'S' ? -$latitude : $latitude,
            strtoupper($m[4]) === 'W' ? -$longitude : $longitude,
        ];
    }

    /**
     * The self-service preview/edit link only gets someone as far as editing content —
     * it was never wired to the actual "create an account" step (a separate /claim/{token}
     * URL only ever sent as a second link in the invite email). Surface it here too, but
     * only while there's still an account worth creating: once claimed, the owner already
     * has one and should use the Filament partner portal instead.
     */
    private static function claimUrl(Listing $listing, bool $isOwnerPreview): ?string
    {
        if (! $isOwnerPreview || ! $listing->partner || $listing->partner->claimed_at !== null) {
            return null;
        }

        return url("/claim/{$listing->partner->claim_token}");
    }

    /** The property owner's claim_token, already emailed to them, doubles as a preview key. */
    private static function hasValidPreviewToken(Listing $listing, Request $request): bool
    {
        $token = $request->input('preview');

        if (! is_string($token) || $token === '') {
            return false;
        }

        $claimToken = $listing->partner?->claim_token;

        return is_string($claimToken) && $claimToken !== '' && hash_equals($claimToken, $token);
    }

    public function storeInquiry(Request $request, Listing $listing): RedirectResponse
    {
        abort_unless($listing->is_published && $listing->accepts_inquiries, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'travel_dates' => ['nullable', 'string', 'max:255'],
            'check_in' => ['nullable', 'date', 'after_or_equal:today'],
            'check_out' => ['nullable', 'date', 'after:check_in'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $activeStatuses = array_map(
            fn (InquiryStatus $s) => $s->value,
            array_filter(InquiryStatus::cases(), fn (InquiryStatus $s) => $s->isActive())
        );

        $alreadyActive = Inquiry::where('email', $validated['email'])
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($alreadyActive) {
            return back()->withErrors([
                'email' => __('You already have an active booking request in progress. Please wait for it to be resolved before submitting a new one.'),
            ]);
        }

        $inquiry = $listing->inquiries()->create($validated);

        if ($listing->partner?->email) {
            Mail::to($listing->partner->email)->send(new NewInquiryReceived($inquiry));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inquiry sent.')]);

        return back();
    }

    public function storeReview(Request $request, Listing $listing): RedirectResponse
    {
        abort_unless($listing->is_published, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $listing->reviews()->create($validated);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Thanks! Your review will appear once it has been checked.'),
        ]);

        return back();
    }

    /**
     * Send one inquiry to each of several shortlisted listings at once — for
     * travelers comparing a few options from search rather than a single
     * listing's page. Each listing still gets its own Inquiry row (and its
     * own partner notification via InquiryObserver); only the "one active
     * request at a time" gate is checked once, up front, for the whole batch.
     */
    public function storeBatchInquiry(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'listing_ids' => ['required', 'array', 'min:1', 'max:10'],
            'listing_ids.*' => ['integer', 'distinct'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $activeStatuses = array_map(
            fn (InquiryStatus $s) => $s->value,
            array_filter(InquiryStatus::cases(), fn (InquiryStatus $s) => $s->isActive())
        );

        $alreadyActive = Inquiry::where('email', $validated['email'])
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($alreadyActive) {
            return back()->withErrors([
                'email' => __('You already have an active booking request in progress. Please wait for it to be resolved before submitting a new one.'),
            ]);
        }

        $listings = Listing::query()
            ->whereIn('id', $validated['listing_ids'])
            ->where('is_published', true)
            ->where('accepts_inquiries', true)
            ->get();

        if ($listings->isEmpty()) {
            return back()->withErrors([
                'listing_ids' => __('None of the selected listings are currently accepting inquiries.'),
            ]);
        }

        $contact = array_intersect_key($validated, array_flip(['name', 'email', 'phone', 'message']));

        DB::transaction(function () use ($listings, $contact) {
            foreach ($listings as $listing) {
                $listing->inquiries()->create($contact);
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count inquiry sent.|:count inquiries sent.', $listings->count(), ['count' => $listings->count()]),
        ]);

        return back();
    }
}
