export interface ItineraryListingRef {
    id: number | null;
    slug: string | null;
    name: string;
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    price_from: string | null;
    price_currency: string;
}

export interface ItineraryDay {
    day: number;
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
