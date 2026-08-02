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
}

export interface ItineraryDay {
    day: number;
    date?: string | null;
    date_to?: string | null;
    location: string;
    accommodation?: ItineraryListingRef | null;
    activity?: ItineraryListingRef | null;
    restaurant?: ItineraryListingRef | null;
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
