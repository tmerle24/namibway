import type {
    ChatMessage,
    GuestDetails,
    InterviewSlot,
    ItineraryDay,
    ItineraryPlan,
    ListingRecommendation,
    SearchIntent,
    VehicleClass,
} from '@/lib/kaia-types';
import type { PriceUnit } from '@/lib/price-unit';

export type KaiaResponse =
    | { type: 'question'; text: string; awaiting?: InterviewSlot | null }
    | { type: 'itinerary'; plan: ItineraryPlan }
    | { type: 'search_intent'; intent: SearchIntent }
    | {
          type: 'recommendation';
          intro: string;
          listing: ListingRecommendation | null;
      }
    | { type: 'error'; text: string };

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function fetchRegions(): Promise<string[]> {
    const response = await fetch('/kaia/regions', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const data = await response.json();

    return data.regions ?? [];
}

export async function fetchCities(): Promise<string[]> {
    const response = await fetch('/kaia/cities', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const data = await response.json();

    return data.cities ?? [];
}

// Unlike fetchCities() above, not filtered to cities with a published
// listing — Startort/Zielort are routing endpoints, not bookable
// destinations, so e.g. Windhoek must stay selectable even if it has no
// lodge listings of its own.
export async function fetchAllCities(): Promise<string[]> {
    const response = await fetch('/kaia/cities?all=1', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const data = await response.json();

    return data.cities ?? [];
}

export interface RegionCoords {
    lat: number;
    lng: number;
    image?: string | null;
    // The political region this key sits in ("Khomas" for "Windhoek"). Only
    // the DB-backed entries carry it; the static fallback below is about
    // keeping the map drawable, not about labelling.
    region?: string | null;
    // The tourism area ("Etosha" for "Onguma Nature Reserve") — what the trip
    // plan prints as the subtle subtitle beside a stage's place name, with the
    // political region above as its fallback. Null for a place in no area.
    area?: string | null;
}

// Static fallback so the map renders even when DB has no lat/lng rows yet.
// Keys are lowercased; covers regions, cities, national parks, and tourism areas.
const STATIC_REGION_COORDS: Record<string, RegionCoords> = {
    // Administrative regions
    khomas: { lat: -22.5597, lng: 17.0832 },
    erongo: { lat: -22.0, lng: 14.9 },
    hardap: { lat: -24.5, lng: 16.5 },
    kunene: { lat: -19.58, lng: 13.92 },
    otjozondjupa: { lat: -20.46, lng: 17.92 },
    karas: { lat: -27.75, lng: 18.0 },
    kavango: { lat: -18.1, lng: 19.9 },
    'kavango west': { lat: -18.1, lng: 19.9 },
    'kavango east': { lat: -18.0, lng: 20.8 },
    zambezi: { lat: -17.8, lng: 24.5 },
    ohangwena: { lat: -17.5, lng: 16.8 },
    omusati: { lat: -18.4, lng: 14.8 },
    oshana: { lat: -18.45, lng: 15.7 },
    oshikoto: { lat: -18.45, lng: 16.8 },
    omaheke: { lat: -21.8, lng: 20.5 },
    // Cities & towns
    windhoek: { lat: -22.5597, lng: 17.0832 },
    swakopmund: { lat: -22.6784, lng: 14.5258 },
    'walvis bay': { lat: -22.9576, lng: 14.5052 },
    lüderitz: { lat: -26.6481, lng: 15.1594 },
    luderitz: { lat: -26.6481, lng: 15.1594 },
    keetmanshoop: { lat: -26.5769, lng: 18.1324 },
    mariental: { lat: -24.6263, lng: 17.9887 },
    rehoboth: { lat: -23.3196, lng: 17.0861 },
    gobabis: { lat: -22.4614, lng: 18.9725 },
    outjo: { lat: -20.1, lng: 16.15 },
    otjiwarongo: { lat: -20.4633, lng: 16.6528 },
    grootfontein: { lat: -19.5668, lng: 18.1128 },
    tsumeb: { lat: -19.2278, lng: 17.7082 },
    ondangwa: { lat: -17.9215, lng: 15.9514 },
    oshakati: { lat: -17.7875, lng: 15.6986 },
    rundu: { lat: -17.9297, lng: 19.7684 },
    'katima mulilo': { lat: -17.4995, lng: 24.2662 },
    opuwo: { lat: -18.0606, lng: 13.8393 },
    // National parks & protected areas
    etosha: { lat: -18.855, lng: 16.329 },
    'etosha national park': { lat: -18.855, lng: 16.329 },
    'namib-naukluft': { lat: -24.05, lng: 15.95 },
    'namib naukluft': { lat: -24.05, lng: 15.95 },
    sossusvlei: { lat: -24.7282, lng: 15.3436 },
    'fish river canyon': { lat: -27.5783, lng: 17.5836 },
    'bwabwata national park': { lat: -18.1, lng: 22.0 },
    bwabwata: { lat: -18.1, lng: 22.0 },
    waterberg: { lat: -20.46, lng: 17.18 },
    'waterberg plateau': { lat: -20.46, lng: 17.18 },
    // Tourism areas & landmarks
    damaraland: { lat: -20.3, lng: 14.5 },
    twyfelfontein: { lat: -20.5931, lng: 14.3706 },
    'skeleton coast': { lat: -21.0, lng: 13.5 },
    kaokoveld: { lat: -18.0, lng: 13.5 },
    caprivi: { lat: -17.8, lng: 24.5 },
    'caprivi strip': { lat: -17.8, lng: 24.5 },
    palmwag: { lat: -19.9, lng: 13.96 },
    'desert rhino camp': { lat: -19.9, lng: 13.96 },
    'cheetah conservation fund': { lat: -20.46, lng: 17.92 },
    'naukluft mountains': { lat: -24.05, lng: 15.95 },
    spitzkoppe: { lat: -21.8258, lng: 15.1836 },
    'namib desert': { lat: -24.05, lng: 15.95 },
};

export async function fetchRegionCoords(): Promise<
    Record<string, RegionCoords>
> {
    try {
        const response = await fetch('/kaia/region-coords', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const data = await response.json();

        return { ...STATIC_REGION_COORDS, ...(data.coords ?? {}) };
    } catch {
        return { ...STATIC_REGION_COORDS };
    }
}

export interface ListingPreview {
    id: number;
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    name: string;
    slug: string;
    description: string | null;
    short_description: string | null;
    highlights: string[];
    image: string | null;
    gallery: string[];
    region: string | null;
    area: string | null;
    city: string | null;
    address: string | null;
    phone: string | null;
    phone_href: string | null;
    website: string | null;
    price_from: string | null;
    price_currency: string;
    price_unit?: PriceUnit | null;
    duration_minutes: number | null;
    rating: number | null;
    rating_count: number | null;
    accepts_inquiries: boolean;
}

export async function fetchListingPreview(
    slug: string,
): Promise<ListingPreview> {
    const response = await fetch(`/listings/${slug}/preview`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error('Failed to load listing preview');
    }

    const data = await response.json();

    return data.listing as ListingPreview;
}

export interface ListingSearchResult {
    id: number;
    // 'attraction' is not a listing type — it is a thing you go and look at,
    // and it appears here because a day's activity may be either. The rest of
    // the shape is shared so one card and one row serve both.
    type:
        'accommodation' | 'activity' | 'restaurant' | 'vehicle' | 'attraction';
    // Only present when type === 'vehicle'.
    vehicle_category: 'self_drive' | 'guided_tour' | null;
    name: string;
    slug: string;
    short_description: string | null;
    image: string | null;
    gallery: string[];
    highlights: string[];
    region: string | null;
    area: string | null;
    city: string | null;
    // Needed so a listing picked here keeps its trip-map marker once it
    // lands in the plan as an ItineraryListingRef.
    latitude: number | null;
    longitude: number | null;
    price_from: string | null;
    price_currency: string;
    price_unit?: PriceUnit | null;
    duration_minutes: number | null;
    rating: number | null;
    rating_count: number | null;
    is_featured: boolean;
    // Only present when a `referenceCity` was resolvable server-side.
    distance_km: number | null;
}

export interface ListingSearchMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

export type ListingSearchSort =
    | 'featured'
    | 'price_asc'
    | 'price_desc'
    | 'rating'
    | 'popularity'
    | 'distance';

export interface ListingSearchParams {
    type: string;
    // Only meaningful when type === 'vehicle'.
    vehicleCategory?: string;
    city?: string;
    keyword?: string;
    budget?: string;
    minRating?: string;
    // A city name the backend resolves to coordinates for `distance_km` and
    // `sort: 'distance'` — independent of `city` above, which just filters.
    referenceCity?: string;
    maxDistanceKm?: string;
    sort?: ListingSearchSort;
    excludeId?: number | null;
    page?: number;
}

// Backs the itinerary's "change" modal (ListingSwapModal.vue) — the same
// `/listings/search` endpoint the Explore page's filter bar uses, so the two
// never drift on what "city"/"budget"/"keyword" mean.
export async function searchListings(
    params: ListingSearchParams,
): Promise<{ data: ListingSearchResult[]; meta: ListingSearchMeta }> {
    const query = new URLSearchParams({ type: params.type });

    if (params.vehicleCategory) {
        query.set('vehicle_category', params.vehicleCategory);
    }

    if (params.city) {
        query.set('city', params.city);
    }

    if (params.keyword) {
        query.set('keyword', params.keyword);
    }

    if (params.budget) {
        query.set('budget', params.budget);
    }

    if (params.minRating) {
        query.set('min_rating', params.minRating);
    }

    if (params.referenceCity) {
        query.set('reference_city', params.referenceCity);
    }

    if (params.maxDistanceKm) {
        query.set('max_distance_km', params.maxDistanceKm);
    }

    if (params.sort) {
        query.set('sort', params.sort);
    }

    if (params.excludeId != null) {
        query.set('exclude_id', String(params.excludeId));
    }

    query.set('page', String(params.page ?? 1));

    const response = await fetch(`/listings/search?${query}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const data = await response.json();

    return { data: data.data ?? [], meta: data.meta };
}

/**
 * A thing to go and look at, as the trip plan shows it. Deliberately the same
 * shape a listing search result has — the activity picker offers both in one
 * list, so one card design has to serve both — with the fields that cannot
 * apply left null rather than faked.
 */
export interface AttractionSearchResult extends ListingSearchResult {
    attraction_type: string;
    attraction_type_label: string;
}

/** The detail modal's payload: the long text and the operational facts. */
export interface AttractionPreview extends AttractionSearchResult {
    description: string | null;
    access_note: string | null;
    best_time_note: string | null;
    /** null means nobody has checked, not "no" — see the attractions table. */
    requires_4x4: boolean | null;
    requires_permit: boolean | null;
    photos_attribution: string | null;
}

/**
 * Things to see near a day's location. Separate from searchListings because
 * price, rating and vehicle class do not apply here and pretending they do
 * would be the only way to share the query.
 */
export async function searchAttractions(params: {
    keyword?: string;
    referenceCity?: string;
    maxDistanceKm?: string;
}): Promise<AttractionSearchResult[]> {
    const query = new URLSearchParams();

    if (params.keyword) {
        query.set('keyword', params.keyword);
    }

    if (params.referenceCity) {
        query.set('reference_city', params.referenceCity);
    }

    if (params.maxDistanceKm) {
        query.set('max_distance_km', params.maxDistanceKm);
    }

    const response = await fetch(`/attractions/search?${query}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        return [];
    }

    const data = await response.json();

    return data.data ?? [];
}

/**
 * A thing to stop for on the way, as the drive-time box shows it. Same card
 * shape as everything else in the plan, plus what it costs to go there.
 */
export interface RouteStop extends AttractionSearchResult {
    /**
     * Roughly how many extra kilometres this stop adds to the leg — straight
     * line, not road, so it is rendered with a "≈" wherever it is shown. See
     * App\Services\Routing\RouteStopFinder for why it is not a routed number.
     */
    detour_km: number;
}

/** The stops on one leg, in the order they come up through the windscreen. */
export interface RouteLegStops {
    from: string;
    to: string;
    stops: RouteStop[];
}

/**
 * What is worth stopping for on each leg of a route.
 *
 * One call for the whole plan rather than one per leg: the legs change
 * together whenever a day is dragged or a stage added, and most of them have
 * nothing to report.
 */
export async function fetchRouteStops(
    legs: Array<{ from: string; to: string }>,
): Promise<RouteLegStops[]> {
    if (legs.length === 0) {
        return [];
    }

    const query = new URLSearchParams();

    legs.forEach((leg, i) => {
        query.set(`legs[${i}][from]`, leg.from);
        query.set(`legs[${i}][to]`, leg.to);
    });

    try {
        const response = await fetch(`/attractions/along-route?${query}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return [];
        }

        const data = await response.json();

        return data.legs ?? [];
    } catch {
        // A discreet extra line under a driving time is never worth an error
        // state — the plan reads exactly the same without it.
        return [];
    }
}

/**
 * Why a supply stop is worth naming, which is the only thing that makes it
 * different from a filling station the traveller drives past. See
 * App\Services\Routing\SupplyStopFinder.
 */
export interface SupplyReason {
    service: 'fuel' | 'groceries';
    /** How far the road ahead goes without it — straight line, so a lower bound. */
    gap_km: number;
    /** Set where a self-catering stay, rather than the distance, is what makes it matter. */
    before_self_catering: boolean;
}

/** Opening hours, days as keys the browser names in the traveller's own language. */
export interface SupplyOpeningHours {
    raw: string;
    always_open: boolean;
    rules: Array<{ days: string[]; ranges: Array<[string, string]> }>;
}

/** Somewhere to fill up or buy food, as the drive-time box shows it. */
export interface SupplyStop {
    id: number;
    slug: string;
    name: string;
    services: string[];
    fuel_types: string[];
    opening_hours: SupplyOpeningHours | null;
    /** Whether those hours were imported from OpenStreetMap, whose licence asks for the credit. */
    opening_hours_from_osm: boolean;
    note: string | null;
    city: string | null;
    region: string | null;
    detour_km: number;
    /** Whether a human has confirmed it still exists and still sells what it says. */
    verified: boolean;
    reasons: SupplyReason[];
}

export interface SupplyLegStops {
    from: string;
    to: string;
    stops: SupplyStop[];
}

/**
 * Where to fill up and where to buy food on each leg of a route.
 *
 * Its own request rather than a second key on the attraction one: the two fail
 * independently — an empty attraction catalogue must not take the fuel line
 * down with it — and only this one needs the stay each leg arrives at, which
 * is what answers "is anybody cooking their own dinner tonight?". The slug is
 * all the browser sends; whether that stay is self-catering is the server's
 * answer to give.
 */
export async function fetchSupplyStops(
    legs: Array<{ from: string; to: string; staySlug?: string | null }>,
): Promise<SupplyLegStops[]> {
    if (legs.length === 0) {
        return [];
    }

    const query = new URLSearchParams();

    legs.forEach((leg, i) => {
        query.set(`legs[${i}][from]`, leg.from);
        query.set(`legs[${i}][to]`, leg.to);

        if (leg.staySlug) {
            query.set(`legs[${i}][stay_slug]`, leg.staySlug);
        }
    });

    try {
        const response = await fetch(`/supply-stops/along-route?${query}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return [];
        }

        const data = await response.json();

        return data.legs ?? [];
    } catch {
        // Same as the route stops: a discreet extra line under a driving time
        // is never worth an error state.
        return [];
    }
}

export async function fetchAttractionPreview(
    slug: string,
): Promise<AttractionPreview> {
    const response = await fetch(`/attractions/${slug}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(`Attraction ${slug} could not be loaded`);
    }

    return response.json();
}

/** One real, bookable room type — see ListingController::roomTypes. */
/**
 * A tax, levy or fee on top of a room — or already inside its rate, which is
 * the common case here and the reason `included` exists: naming a VAT that
 * changes no total is what stops a traveler adding it twice in their head.
 */
export interface RoomCharge {
    name: string;
    amount: number;
    included: boolean;
}

export interface RoomTypeOffer {
    code: string;
    name: string;
    description: string | null;
    max_adults: number;
    max_children: number;
    /** An average where the stay crosses a season boundary — see `nightly_rates`. */
    price_per_night: number;
    nightly_rates: number[];
    /** The room itself, before anything the property adds on top. */
    total_price: number;
    /** What the traveler pays: the stay plus every charge that is not already in the rate. */
    total_payable: number;
    charges: RoomCharge[];
    currency: string;
    units_left: number;
    /** The room's own photos. Empty is normal — no room photos uploaded yet. */
    gallery: string[];
}

/**
 * What a listing actually has free for a stay. An empty `rooms` list is the
 * ordinary answer for most listings today (no room inventory held for them),
 * and means "ask the partner" — never "make something up", which is what the
 * picker used to do.
 */
/** One bookable departure — matches AvailabilityController's slot shape. */
export interface AvailabilitySlot {
    starts_at: string;
    label: string;
    duration_minutes: number;
    units_left: number;
    total: number;
    total_payable: number;
}

/**
 * One bookable unit returned by GET /availability.
 *
 * Slot-based units (activities) have `slots` and null totals at the unit level;
 * date-range units (accommodation, vehicle) have a `total` and empty `slots`.
 * See UnitOffer.php and BOOKING_BEYOND_ROOMS.md §7.1.
 */
export interface AvailabilityUnit {
    code: string;
    name: string;
    description: string | null;
    max_adults: number;
    max_children: number;
    slots: AvailabilitySlot[];
    total: number | null;
    total_payable: number | null;
    charges: RoomCharge[];
    currency: string;
    units_left: number | null;
    gallery: string[];
}

export async function fetchAvailability(
    slug: string,
    params: {
        checkIn: string;
        checkOut?: string;
        time?: string;
        adults?: number;
        children?: number;
    },
): Promise<{ periods: number; units: AvailabilityUnit[] }> {
    const query = new URLSearchParams({
        listing: slug,
        check_in: params.checkIn,
    });

    if (params.checkOut) {
        query.set('check_out', params.checkOut);
    }

    if (params.time) {
        query.set('time', params.time);
    }

    if (params.adults) {
        query.set('adults', String(params.adults));
    }

    if (params.children) {
        query.set('children', String(params.children));
    }

    const response = await fetch(`/availability?${query}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error('Failed to load availability');
    }

    const data = await response.json();

    return {
        periods: (data.periods as number) ?? 1,
        units: (data.units as AvailabilityUnit[]) ?? [],
    };
}

export async function fetchRoomTypes(
    slug: string,
    params: {
        checkIn: string;
        checkOut: string;
        adults?: number;
        children?: number;
    },
): Promise<{ nights: number; rooms: RoomTypeOffer[] }> {
    const query = new URLSearchParams({
        check_in: params.checkIn,
        check_out: params.checkOut,
    });

    if (params.adults) {
        query.set('adults', String(params.adults));
    }

    if (params.children) {
        query.set('children', String(params.children));
    }

    const response = await fetch(`/listings/${slug}/room-types?${query}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error('Failed to load room types');
    }

    const data = await response.json();

    return {
        nights: (data.nights as number) ?? 1,
        rooms: (data.rooms as RoomTypeOffer[]) ?? [],
    };
}

export interface SavedPlanResult {
    /** Grants editing — the creator's own link. Never hand this to anyone else. */
    token: string;
    url: string;
    /** Read-only counterpart: same plan, no write access. This is what "share" means. */
    shareToken: string | null;
    shareUrl: string | null;
    version: number;
    /**
     * Whether the plan landed in an account. Creating one never requires
     * login — it's only true when the traveler happened to be logged in
     * already. Attaching an anonymous plan to an account is claimPlan().
     */
    owned: boolean;
}

/**
 * Thrown when the server refused because the traveler isn't logged in (401) or
 * isn't the plan's creator (403). Distinct from a transport failure: the caller
 * should offer a login, not a retry.
 */
export class AuthRequiredError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'AuthRequiredError';
    }
}

// Thrown by updatePlan() when the server rejected the write because the plan
// moved on since it was loaded — the share link is the same token everyone
// edits with, so this is a normal outcome, not a transport failure. Carries
// the server's current state so a caller can show it rather than guess.
export class PlanConflictError extends Error {
    constructor(
        readonly serverVersion: number | null,
        readonly serverPlan: ItineraryPlan | null,
    ) {
        super('Plan changed elsewhere');
        this.name = 'PlanConflictError';
    }
}

export async function savePlan(plan: ItineraryPlan): Promise<SavedPlanResult> {
    const response = await fetch('/kaia/plans', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ variant: plan }),
    });

    if (!response.ok) {
        throw new Error('Failed to save plan');
    }

    const data = await response.json();
    const token = data.token as string;

    const shareToken = (data.share_token as string) ?? null;

    return {
        token,
        url: `${window.location.origin}/trip/${token}`,
        shareToken,
        shareUrl: shareToken
            ? `${window.location.origin}/trip/${shareToken}`
            : null,
        version: (data.version as number) ?? 1,
        owned: (data.owned as boolean) ?? false,
    };
}

/**
 * Attach an already-persisted plan to the logged-in account — what the Save
 * button means. The server rejects this without a session (401) and refuses a
 * plan that belongs to someone else (403), so this is the account line rather
 * than a UI convention. Requires the plan's *edit* token; a read-only share
 * token 404s.
 */
export async function claimPlan(token: string): Promise<void> {
    const response = await fetch(`/kaia/plans/${token}/claim`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });

    if (response.status === 401 || response.status === 403) {
        const data = await response.json().catch(() => ({}));

        throw new AuthRequiredError(
            (data as { message?: string }).message ?? 'Login required',
        );
    }

    if (!response.ok) {
        throw new Error('Failed to save plan to account');
    }
}

export interface LoadedPlan {
    plan: ItineraryPlan;
    // What the next updatePlan() has to send back for the server to be able to
    // spot a stale write.
    version: number;
    // False when the token used was a read-only share link. The server rejects
    // writes either way; this is what lets the UI stop offering them.
    canEdit: boolean;
    shareToken: string | null;
    /** True only for the plan's own owner — see SavedPlanResult.owned. */
    owned: boolean;
}

export async function loadPlan(token: string): Promise<LoadedPlan> {
    const response = await fetch(`/kaia/plans/${token}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error('Failed to load plan');
    }

    const data = await response.json();

    return {
        plan: data.variant as ItineraryPlan,
        version: (data.version as number) ?? 1,
        canEdit: (data.can_edit as boolean) ?? true,
        shareToken: (data.share_token as string) ?? null,
        owned: (data.owned as boolean) ?? false,
    };
}

/** Returns the plan's new version. Throws PlanConflictError on a stale write. */
export async function updatePlan(
    token: string,
    plan: ItineraryPlan,
    version: number | null,
): Promise<number> {
    const response = await fetch(`/kaia/plans/${token}`, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(
            version === null ? { variant: plan } : { variant: plan, version },
        ),
    });

    if (response.status === 409) {
        const data = await response.json().catch(() => ({}));

        throw new PlanConflictError(
            (data.version as number) ?? null,
            (data.variant as ItineraryPlan) ?? null,
        );
    }

    if (!response.ok) {
        throw new Error('Failed to update plan');
    }

    const data = await response.json();

    return (data.version as number) ?? (version ?? 0) + 1;
}

/**
 * `planToken` is the plan's edit token, and it's required: the server uses it
 * to establish that the person booking is the plan's creator and not someone
 * who was shown it (see TripController::store). Booking also needs an account —
 * a 401/403 comes back as AuthRequiredError so the caller can offer a login
 * instead of a pointless retry.
 */
export async function createTrip(
    details: GuestDetails,
    variantName: string,
    plan: ItineraryPlan,
    variantDays: ItineraryDay[],
    planToken: string,
): Promise<{ trip_id: number; inquiry_count: number }> {
    const response = await fetch('/trips', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({
            ...details,
            variant_name: variantName,
            plan,
            variant_days: variantDays,
            plan_token: planToken,
        }),
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        const message = (data as { error?: string }).error;

        if (response.status === 401 || response.status === 403) {
            throw new AuthRequiredError(message ?? 'Login required');
        }

        throw new Error(message ?? 'Booking failed. Please try again.');
    }

    return response.json();
}

export interface TripInquiryStatus {
    listing_name: string;
    status: string;
    label: string;
}

export async function fetchTripInquiries(
    tripId: number,
): Promise<TripInquiryStatus[]> {
    const response = await fetch(`/trips/${tripId}/inquiries`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const data = await response.json();

    return (data as { inquiries: TripInquiryStatus[] }).inquiries ?? [];
}

export interface SupportPayload {
    name: string;
    email: string;
    message: string;
    trip_id?: number | null;
}

export async function sendSupport(payload: SupportPayload): Promise<void> {
    const response = await fetch('/support', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new Error('Failed to send support message.');
    }
}

export interface FeedbackPayload {
    name?: string | null;
    message: string;
    rating?: number | null;
    trip_id?: number | null;
}

export async function sendFeedback(payload: FeedbackPayload): Promise<void> {
    const response = await fetch('/feedback', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new Error('Failed to send feedback.');
    }
}

export interface RegeneratePlanParams {
    nights: number;
    travel_period: string;
    interests: string;
    budget_tier: string;
    adults: number;
    children_under_13: number;
    children_ages: string | null;
    vehicle_type: string;
    vehicle_class: VehicleClass | null;
    vehicle_daily_budget: number | null;
    start_location: string;
    end_location: string;
}

export async function regeneratePlan(
    params: RegeneratePlanParams,
): Promise<ItineraryPlan> {
    const response = await fetch('/kaia/regenerate', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(params),
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));

        throw new Error(
            (data as { error?: string }).error ?? 'Could not update the plan.',
        );
    }

    const data = await response.json();

    return data.plan as ItineraryPlan;
}

/**
 * A request Kaia refused rather than one that failed on the way there.
 *
 * `retryable` is the whole point: a 5xx or a dropped connection usually comes
 * good on the second attempt, and the chat retries those silently. A rejected
 * session or a spent rate limit will answer identically however many times we
 * ask, so re-sending only delays telling the traveler something they have to
 * act on. `reload` says which action actually fixes it.
 */
export class KaiaRequestError extends Error {
    constructor(
        readonly status: number,
        readonly retryable: boolean,
        readonly reload = false,
    ) {
        super(`Kaia request failed with ${status}`);
        this.name = 'KaiaRequestError';
    }
}

export async function sendKaiaMessage(
    history: ChatMessage[],
): Promise<KaiaResponse> {
    const response = await fetch('/kaia/message', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ history }),
    });

    // Every other endpoint in this file checks this; this one — the most used
    // in the product — did not, and the way it failed was the worst available.
    // Laravel answers an `Accept: application/json` request with JSON at every
    // status, so a 419, 422, 429 or 500 parsed cleanly into an object with no
    // `type` field, matched none of the branches in HeroChat's applyResult,
    // and was counted as a turn that had been handled. The traveler's own
    // message stayed on screen, the thinking indicator stopped, and nothing
    // else ever happened — no error, nothing to retry, nothing to report.
    if (!response.ok) {
        // 419 is a session whose CSRF token no longer matches — long-lived
        // wrapped-app webviews reach it just by being left open. Only a fresh
        // page mints a new token, so retrying with this one is a loop.
        if (response.status === 419) {
            throw new KaiaRequestError(419, false, true);
        }

        // Everything else: a 5xx (or a proxy timeout) is the kind of failure
        // that comes good on its own, so the chat may retry it quietly. Any
        // other 4xx — our own throttle at 20 requests a minute, a payload the
        // validator rejected — will be rejected identically however often it
        // is re-sent, so it is said once instead.
        const retryable = response.status >= 500 || response.status === 408;

        throw new KaiaRequestError(response.status, retryable);
    }

    return response.json();
}
