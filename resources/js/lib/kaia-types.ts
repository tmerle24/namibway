export interface ItineraryListingRef {
    id: number | null;
    slug: string | null;
    name: string;
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
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
    estimated_total_usd: number;
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
