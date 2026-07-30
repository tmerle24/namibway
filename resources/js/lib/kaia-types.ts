export interface ItineraryListingRef {
    id: number | null;
    slug: string | null;
    name: string;
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    price_from: string | null;
    price_currency: string;
    lat?: number | null;
    lng?: number | null;
}

export interface ItineraryDay {
    day: number;
    date?: string | null;
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

export interface ItineraryPlan {
    trip_summary: string;
    variants: ItineraryVariant[];
}

export interface ChatMessage {
    role: 'ai' | 'user';
    text: string;
    recommendation?: ListingRecommendation | null;
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
