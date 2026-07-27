import type {
    ChatMessage,
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

export async function fetchRegionCoords(): Promise<Record<string, RegionCoords>> {
    const response = await fetch('/kaia/region-coords', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const data = await response.json();

    return data.coords ?? {};
}

export async function fetchAlternatives(type: string, excludeId?: number): Promise<ItineraryListingRef[]> {
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

    if (!response.ok) throw new Error('Failed to save plan');

    return response.json();
}

export async function sendKaiaMessage(history: ChatMessage[]): Promise<KaiaResponse> {
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
