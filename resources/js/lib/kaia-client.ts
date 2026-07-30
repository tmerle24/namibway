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
    'lüderitz': { lat: -26.6481, lng: 15.1594 },
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
    'spitzkoppe': { lat: -21.8258, lng: 15.1836 },
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

    return { token, url: `${window.location.origin}/trip/${token}` };
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
