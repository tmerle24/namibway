export interface ItineraryListingRef {
    id: number | null;
    slug: string | null;
    name: string;
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    // Only meaningful when type === 'vehicle'.
    vehicle_category?: 'self_drive' | 'guided_tour' | null;
    price_from: string | null;
    price_currency: string;
    // Typical length of the experience in minutes (activities and guided
    // tours). Part of what the traveler books — a 2h quad ride is a different
    // product from a full-day one — so it rides along in the plan's own copy
    // of the listing rather than being looked up again at display time.
    duration_minutes?: number | null;
    lat?: number | null;
    lng?: number | null;
    image?: string | null;
    gallery?: string[];
    city?: string | null;
    region?: string | null;
    // The one-line "why this one" detail the stay card shows under the name
    // (see ItineraryStayCard.vue). Carried in the plan's own copy of the
    // listing so a saved/shared plan renders without refetching listings.
    short_description?: string | null;
    rating?: number | null;
    rating_count?: number | null;
    // Time of day this entry happens (24h "HH:MM") — only meaningful for
    // type === 'activity' | 'restaurant'. Drives the merged day timeline's
    // sort order in ItinerarySection.vue; null/unset entries sink to the end.
    time?: string | null;
}

// Placeholder room-type content — not backed by RoomType/live availability
// yet (see RoomTypePicker.vue). Good enough to demo the picking UX; swap
// the generator for a real per-stay availability call once that's wired up.
export interface RoomOption {
    code: string;
    name: string;
    capacity: string;
    price_per_night: number;
    currency: string;
}

export interface ItineraryDay {
    day: number;
    date?: string | null;
    date_to?: string | null;
    location: string;
    // The political region `location` sits in, resolved server-side (see
    // ItineraryService::normalizeDayLocations). Absent on saved plans made
    // before that existed — the UI falls back to the accommodation's own
    // region, then to a lookup by city name.
    region?: string | null;
    accommodation?: ItineraryListingRef | null;
    // Arrays — Kaia itself still only ever fills in one of each, but the
    // traveler can add a 2nd/3rd manually (see ItinerarySection.vue's
    // openSwap 'add' mode). Plans coming straight from the backend (or
    // older saved plans) carry the singular `activity`/`restaurant` fields
    // instead — normalizeVariants() in ItinerarySection.vue wraps those into
    // these arrays before they ever reach the rest of the UI.
    activities?: ItineraryListingRef[];
    restaurants?: ItineraryListingRef[];
    // Raw shape as sent by the backend — never read directly outside
    // normalizeVariants().
    activity?: ItineraryListingRef | null;
    restaurant?: ItineraryListingRef | null;
    room_selection?: RoomOption | null;
}

// One row of a day's merged chronological plan — an activity and a restaurant
// entry live in the same list, told apart by `type` rather than by which array
// they came from. `itemIndex` is the entry's position in its OWN source array
// (day.activities / day.restaurants), not in the merged list, so remove/swap
// keep addressing the right element after sorting. Built by dayEntries() in
// ItinerarySection.vue, consumed by ItineraryDayPlanCard.vue.
export interface DayEntry {
    type: 'activity' | 'restaurant';
    item: ItineraryListingRef;
    itemIndex: number;
}

export interface ItineraryVariant {
    name: string;
    vehicle?: ItineraryListingRef | null;
    days: ItineraryDay[];
}

export interface TripParams {
    nights: number | null;
    travel_period: string;
    interests: string;
    adults: number;
    children_under_13: number;
    children_ages?: string | null;
    vehicle_type: string;
    budget_tier: string;
}

export interface ItineraryPlan {
    trip_summary: string;
    variants: ItineraryVariant[];
    start_location?: string;
    end_location?: string;
    trip_params?: TripParams | null;
}

export interface ChatMessage {
    role: 'ai' | 'user';
    text: string;
    recommendation?: ListingRecommendation | null;
    // Marks a placeholder bubble shown after Kaia failed to respond (all
    // silent retries exhausted) — rendered with a retry action instead of
    // being treated as a real turn, and excluded from the history sent back
    // to the API so it never pollutes the conversation Claude sees.
    failed?: boolean;
}

export interface ListingRecommendation {
    id: number;
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    name: string;
    slug: string;
    region: string | null;
    image: string | null;
    price_from: string | null;
    price_currency: string;
    rating: number | null;
    rating_count: number | null;
}

export interface SearchIntent {
    type?: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    region?: string;
    city?: string;
    keyword?: string;
    budget?: 'budget' | 'mid-range' | 'premium';
    min_rating?: string;
    sort?: string;
}

export interface GuestDetails {
    name: string;
    email: string;
    phone: string;
    check_in: string;
    check_out: string;
    adults: number;
    children: number;
}
