import type {
    ChatMessage,
    GuestDetails,
    ItineraryDay,
    ItineraryListingRef,
    ItineraryPlan,
} from '@/lib/kaia-types';

export type KaiaResponse =
    | { type: 'question'; text: string }
    | { type: 'itinerary'; plan: ItineraryPlan }
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

export interface RegionCoords {
    lat: number;
    lng: number;
}

// Static fallback so the map renders even when DB has no lat/lng rows yet
const STATIC_REGION_COORDS: Record<string, RegionCoords> = {
    khomas: { lat: -22.5597, lng: 17.0832 },
    erongo: { lat: -22.0, lng: 14.9 },
    hardap: { lat: -24.5, lng: 16.5 },
    kunene: { lat: -19.58, lng: 13.92 },
    etosha: { lat: -18.855, lng: 16.329 },
    otjozondjupa: { lat: -20.46, lng: 17.92 },
    karas: { lat: -27.75, lng: 18.0 },
    kavango: { lat: -18.1, lng: 19.9 },
    zambezi: { lat: -17.8, lng: 24.5 },
    ohangwena: { lat: -17.5, lng: 16.8 },
    omusati: { lat: -18.4, lng: 14.8 },
    oshana: { lat: -18.45, lng: 15.7 },
    oshikoto: { lat: -18.45, lng: 16.8 },
    omaheke: { lat: -21.8, lng: 20.5 },
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
        // DB values take precedence; static fallback fills any gaps
        return { ...STATIC_REGION_COORDS, ...(data.coords ?? {}) };
    } catch {
        return { ...STATIC_REGION_COORDS };
    }
}

export async function fetchAlternatives(
    type: string,
    excludeId?: number,
): Promise<ItineraryListingRef[]> {
    const params = new URLSearchParams({ type });

    if (excludeId !== undefined) {
        params.set('exclude_id', String(excludeId));
    }

    const response = await fetch(`/kaia/alternatives?${params}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const data = await response.json();

    return data.alternatives ?? [];
}

export interface SavedPlanResult {
    token: string;
    url: string;
}

export async function savePlan(plan: ItineraryPlan): Promise<SavedPlanResult> {
    const response = await fetch('/trip/save', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ plan }),
    });

    if (!response.ok) {
        throw new Error('Failed to save plan');
    }

    return response.json();
}

export async function createTrip(
    details: GuestDetails,
    variantName: string,
    plan: ItineraryPlan,
    variantDays: ItineraryDay[],
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
        }),
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));

        throw new Error(
            (data as { error?: string }).error ??
                'Booking failed. Please try again.',
        );
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

    return response.json();
}
