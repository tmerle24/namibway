export interface ItineraryListingRef {
    id: number | null;
    slug: string | null;
    name: string;
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    price_from: string | null;
    price_currency: string;
    lat?: number | null;
    lng?: number | null;
    image?: string | null;
    gallery?: string[];
    city?: string | null;
    region?: string | null;
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
