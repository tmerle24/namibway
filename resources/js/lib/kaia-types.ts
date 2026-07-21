export interface ItineraryDay {
    day: number;
    location: string;
    accommodation?: string;
    activity?: string;
    restaurant?: string;
}

export interface ItineraryVariant {
    name: string;
    estimated_total_usd: number;
    vehicle?: string;
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
