<?php

namespace App\Http\Controllers;

use App\Enums\InquiryStatus;
use App\Jobs\EnrichListingJob;
use App\Mail\NewInquiryReceived;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class ListingController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = Listing::query()->where('is_published', true);

        $type = $request->query('type');

        if (is_string($type) && $type !== '') {
            $query->where('type', $type);
        }

        $region = $request->query('region');

        if (is_string($region) && $region !== '') {
            $query->where('region', 'ilike', '%'.$region.'%');
        }

        $keyword = $request->query('keyword');

        if (is_string($keyword) && $keyword !== '') {
            $kw = '%'.mb_strtolower($keyword).'%';
            $query->where(function ($q) use ($kw) {
                $q->whereRaw('lower(cast(name as text)) like ?', [$kw])
                    ->orWhereRaw('lower(cast(description as text)) like ?', [$kw])
                    ->orWhereRaw('lower(cast(region as text)) like ?', [$kw])
                    ->orWhereRaw('lower(cast(type as text)) like ?', [$kw]);
            });
        }

        $budget = $request->query('budget');

        if (is_string($budget)) {
            if ($budget === 'budget') {
                $query->where(function ($q) {
                    $q->where('price_from', '<', 150)->orWhereNull('price_from');
                });
            } elseif ($budget === 'mid-range') {
                $query->whereBetween('price_from', [150, 400]);
            } elseif ($budget === 'premium') {
                $query->where('price_from', '>', 400);
            }
        }

        $minRating = $request->query('min_rating');

        if (is_string($minRating) && $minRating !== '') {
            $query->where('rating', '>=', (float) $minRating);
        }

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
                'price_from' => $listing->price_from,
                'price_currency' => $listing->price_currency,
                'rating' => $listing->rating !== null ? (float) $listing->rating : null,
                'rating_count' => $listing->rating_count,
                'accepts_inquiries' => $listing->accepts_inquiries,
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
     * for. Deliberately a small field set: the basics an owner would actually want to
     * fix themselves, not the full admin surface (no photos — that's the separate
     * approve flow — no taxonomy/GPS, which stay data-integrity-sensitive/admin-only).
     */
    public function edit(Request $request, Listing $listing): Response
    {
        abort_unless(self::isAdmin() || self::hasValidPreviewToken($listing, $request), 403);

        return Inertia::render('ListingEdit', [
            'listing' => [
                'id' => $listing->id,
                'slug' => $listing->slug,
                'name' => $listing->getTranslation('name', 'en', useFallbackLocale: false),
                'description' => $listing->getTranslation('description', 'en', useFallbackLocale: false),
                'short_description' => $listing->getTranslation('short_description', 'en', useFallbackLocale: false),
                'highlights' => $listing->getTranslation('highlights', 'en', useFallbackLocale: false) ?? [],
                'phone' => $listing->phone,
                'contact_email' => $listing->contact_email,
                'website' => $listing->website,
                'address' => $listing->address,
                'price_from' => $listing->price_from,
                'price_currency' => $listing->price_currency,
                'is_published' => $listing->is_published,
            ],
            'preview_token' => self::hasValidPreviewToken($listing, $request) ? $request->input('preview') : null,
        ]);
    }

    public function update(Request $request, Listing $listing): RedirectResponse
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
            'phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'price_from' => ['nullable', 'numeric', 'min:0'],
            'price_currency' => ['nullable', 'string', 'max:3'],
            'publish' => ['nullable', 'boolean'],
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
        $listing->fill(array_filter([
            'phone' => $validated['phone'] ?? null,
            'contact_email' => $validated['contact_email'] ?? null,
            'website' => $validated['website'] ?? null,
            'address' => $validated['address'] ?? null,
            'price_from' => $validated['price_from'] ?? null,
            'price_currency' => $validated['price_currency'] ?? null,
        ], fn ($value) => $value !== null));

        if (! empty($validated['publish'])) {
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
