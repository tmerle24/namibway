<script setup lang="ts">
import type { Map as LeafletMap, Marker, Polyline } from 'leaflet';
import { onMounted, onUnmounted, watch } from 'vue';

export interface DrivingLeg {
    from: string;
    to: string;
    seconds: number;
}
import DuneSkyline from '@/components/DuneSkyline.vue';
import type { RegionCoords } from '@/lib/kaia-client';
import type { ItineraryDay, ItineraryVariant } from '@/lib/kaia-types';

interface OsrmLeg {
    duration: number;
    distance: number;
}

function formatDuration(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);

    if (h === 0) {
        return `${m} min`;
    }

    if (m === 0) {
        return `${h} h`;
    }

    return `${h} h ${m} min`;
}

async function fetchDrivingLegs(
    latlngs: [number, number][],
): Promise<OsrmLeg[]> {
    if (latlngs.length < 2) {
        return [];
    }

    const coords = latlngs.map(([lat, lng]) => `${lng},${lat}`).join(';');
    const url = `https://router.project-osrm.org/route/v1/driving/${coords}?overview=false&steps=false`;

    try {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 6000);
        const res = await fetch(url, { signal: ctrl.signal });
        clearTimeout(timer);

        if (!res.ok) {
            return [];
        }

        const data = (await res.json()) as {
            code: string;
            routes: Array<{ legs: OsrmLeg[] }>;
        };

        return data.code === 'Ok' ? (data.routes[0]?.legs ?? []) : [];
    } catch {
        return [];
    }
}

const props = defineProps<{
    variant: ItineraryVariant;
    regionCoords: Record<string, RegionCoords>;
    mapId: string;
}>();

const emit = defineEmits<{
    'driving-legs': [legs: DrivingLeg[]];
}>();

let map: LeafletMap | null = null;
let markers: Marker[] = [];
let routeLine: Polyline | null = null;

// Namibia bounding box: SW(-29.5, 11.5) → NE(-16.9, 25.3)
const NAMIBIA_BOUNDS: [[number, number], [number, number]] = [
    [-29.5, 11.5],
    [-16.9, 25.3],
];

async function initMap(containerId: string) {
    const L = (await import('leaflet')).default;
    await import('leaflet/dist/leaflet.css');

    // Fix default icon paths broken by Vite's asset hashing
    delete (L.Icon.Default.prototype as unknown as Record<string, unknown>)
        ._getIconUrl;
    L.Icon.Default.mergeOptions({
        iconRetinaUrl:
            'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        shadowUrl:
            'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
    });

    map = L.map(containerId, {
        zoomControl: true,
        scrollWheelZoom: false,
    });

    map.fitBounds(NAMIBIA_BOUNDS);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution:
            '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 18,
    }).addTo(map);

    renderRoute();
}

function resolveCoords(day: ItineraryDay): [number, number] | null {
    // Prefer exact accommodation coordinates from the DB
    const acc = day.accommodation;

    if (acc?.lat != null && acc?.lng != null) {
        return [acc.lat, acc.lng];
    }

    // Fall back to city-level coordinates (day.location — see resolveCoords' caller)
    const key = day.location?.toLowerCase().trim();

    if (!key) {
        return null;
    }

    let coords = props.regionCoords[key];

    if (!coords) {
        const fallbackKey = Object.keys(props.regionCoords).find(
            (k) => key.includes(k) || k.includes(key),
        );

        if (fallbackKey) {
            coords = props.regionCoords[fallbackKey];
        }
    }

    return coords ? [coords.lat, coords.lng] : null;
}

function coordKey(latlng: [number, number]): string {
    return `${latlng[0].toFixed(4)},${latlng[1].toFixed(4)}`;
}

function renderRoute() {
    if (!map) {
        return;
    }

    import('leaflet').then(({ default: L }) => {
        markers.forEach((m) => m.remove());
        markers = [];

        if (routeLine) {
            routeLine.remove();
            routeLine = null;
        }

        // Build waypoints — deduplicate by coordinate proximity, not location string,
        // so a round-trip (Windhoek → Etosha → Windhoek) shows all three stops.
        const waypoints: Array<{
            latlng: [number, number];
            label: string;
            cityLabel: string;
            day: number;
            date: string | null;
            accommodationName: string | null;
            accommodationSlug: string | null;
        }> = [];
        const seenCoords = new Set<string>();

        for (const day of props.variant.days) {
            const latlng = resolveCoords(day);

            if (!latlng) {
                continue;
            }

            const ck = coordKey(latlng);

            // Skip only if this exact position already appears as the previous stop
            const lastCk =
                waypoints.length > 0
                    ? coordKey(waypoints[waypoints.length - 1].latlng)
                    : null;

            if (ck === lastCk) {
                continue;
            }

            seenCoords.add(ck);
            waypoints.push({
                latlng,
                // Kept as day.location (now always a city, see the AI contract
                // in ItineraryService) — driving-leg matching in
                // ItinerarySection.vue compares against this same value. The
                // popup below shows cityLabel instead in case an older saved
                // plan still has a region here rather than a city.
                label: day.location,
                cityLabel: day.accommodation?.city || day.location,
                day: day.day,
                date: day.date ?? null,
                accommodationName: day.accommodation?.name ?? null,
                accommodationSlug: day.accommodation?.slug ?? null,
            });
        }

        if (waypoints.length === 0) {
            map!.fitBounds(NAMIBIA_BOUNDS);

            return;
        }

        const latlngs = waypoints.map((w) => w.latlng);

        routeLine = L.polyline(latlngs, {
            color: '#c0533a',
            weight: 2.5,
            dashArray: '6 5',
            opacity: 0.85,
        }).addTo(map!);

        waypoints.forEach((wp, index) => {
            const isStart = index === 0;
            const isEnd =
                index === waypoints.length - 1 && waypoints.length > 1;
            const markerClass = [
                'trip-map-marker',
                isStart && 'trip-map-marker--start',
                isEnd && 'trip-map-marker--end',
            ]
                .filter(Boolean)
                .join(' ');

            const icon = L.divIcon({
                className: '',
                html: `<div class="${markerClass}">${wp.day}</div>`,
                iconSize: [28, 28],
                iconAnchor: [14, 14],
            });

            const accHtml = wp.accommodationName
                ? wp.accommodationSlug
                    ? `<a href="/listings/${wp.accommodationSlug}" target="_blank" rel="noopener" class="map-popup-link">${wp.accommodationName}</a>`
                    : wp.accommodationName
                : '';

            const popupLines = [
                `<div class="map-popup">`,
                `<div class="map-popup-day">Day ${wp.day}${wp.date ? `<span class="map-popup-date"> · ${wp.date}</span>` : ''}</div>`,
                `<div class="map-popup-location">${wp.cityLabel}</div>`,
                accHtml
                    ? `<div class="map-popup-accommodation">${accHtml}</div>`
                    : '',
                `</div>`,
            ].join('');

            const marker = L.marker(wp.latlng, { icon })
                .addTo(map!)
                .bindPopup(popupLines, { maxWidth: 200 });

            marker.on('mouseover', () => marker.openPopup());

            // On a round trip, day 1 and the last day often sit at the exact
            // same coordinates (e.g. both in Windhoek). Leaflet stacks
            // same-pane markers by zIndexOffset (falling back to add order),
            // so without this the arrival marker — added last — would sit on
            // top and hide the green "1" start marker underneath it.
            if (isStart) {
                marker.setZIndexOffset(1000);
            }

            markers.push(marker);
        });

        if (waypoints.length === 1) {
            map!.setView(waypoints[0].latlng, 7);
        } else {
            map!.fitBounds(L.latLngBounds(latlngs), { padding: [56, 40] });
        }

        // Fetch driving times between consecutive stops, render labels and emit for plan display
        if (waypoints.length >= 2) {
            fetchDrivingLegs(latlngs).then((legs) => {
                if (!map) {
                    return;
                }

                const emittedLegs: DrivingLeg[] = [];

                legs.forEach((leg, i) => {
                    const from = latlngs[i];
                    const to = latlngs[i + 1];

                    if (!from || !to) {
                        return;
                    }

                    const midLat = (from[0] + to[0]) / 2;
                    const midLng = (from[1] + to[1]) / 2;
                    const label = formatDuration(leg.duration);
                    const timeIcon = L.divIcon({
                        className: '',
                        html: `<div class="trip-map-drive-label">${label}</div>`,
                        iconSize: [80, 20],
                        iconAnchor: [40, 10],
                    });
                    const m = L.marker([midLat, midLng], {
                        icon: timeIcon,
                        interactive: false,
                    }).addTo(map!);
                    markers.push(m);

                    emittedLegs.push({
                        from: waypoints[i].label,
                        to: waypoints[i + 1]?.label ?? '',
                        seconds: leg.duration,
                    });
                });

                emit('driving-legs', emittedLegs);
            });
        }
    });
}

onMounted(() => {
    initMap(props.mapId);
});

onUnmounted(() => {
    map?.remove();
    map = null;
});

watch(() => [props.variant, props.regionCoords] as const, renderRoute, {
    deep: true,
});
</script>

<template>
    <div class="trip-map-frame">
        <div class="trip-map-wrapper">
            <div :id="mapId" class="trip-map-container" />
        </div>
        <DuneSkyline class="trip-map-deco" />
    </div>
</template>

<style scoped>
.trip-map-frame {
    margin: 20px 0 4px;
    border-radius: 14px;
    overflow: hidden;
    background: var(--paper, #fbf8f1);
    border: 1px solid var(--sand-dark, #d6c9b5);
    box-shadow: 0 8px 22px rgba(36, 28, 21, 0.14);
    /* Leaflet's own stylesheet gives its zoom control/attribution panes
       z-index values up to 1000 — harmless while this frame has its own
       stacking context, but without one those values compare directly
       against page-level fixed elements (e.g. the mobile footer nav) and
       paint on top of them regardless of the footer's own z-index. */
    position: relative;
    isolation: isolate;
}

.trip-map-wrapper {
    margin: 0 auto;
    max-width: 420px;
    aspect-ratio: 3 / 4;
}

/* Small decorative strip below the map — the same dune/sun illustration
   used to sit full-size behind the map itself; preserveAspectRatio="xMidYMax
   slice" on DuneSkyline crops it bottom-anchored, so a short strip like this
   just shows the dune silhouettes. */
.trip-map-deco {
    display: block;
    width: 100%;
    height: 56px;
}

.trip-map-container {
    width: 100%;
    height: 100%;
    background: #f0ece4;
}
</style>

<style>
.trip-map-marker {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #c0533a;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #fff;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
    font-family: inherit;
    cursor: pointer;
    transition:
        transform 0.15s ease,
        box-shadow 0.15s ease;
}

.trip-map-marker:hover {
    transform: scale(1.2);
    box-shadow: 0 4px 12px rgba(192, 83, 58, 0.5);
}

.trip-map-marker--start {
    background: #2f7d4f;
}

.trip-map-marker--end {
    background: #1a1a1a;
}

.map-popup {
    font-family: inherit;
    min-width: 120px;
}

.map-popup-day {
    font-size: 10px;
    font-weight: 600;
    color: #c0533a;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 2px;
}

.map-popup-location {
    font-size: 13px;
    font-weight: 700;
    color: #2c2521;
    margin-bottom: 3px;
}

.map-popup-date {
    font-weight: 400;
    color: #8a7d6e;
}

.map-popup-accommodation {
    font-size: 11px;
    color: #6b5f54;
}

.map-popup-link {
    color: #c0533a;
    text-decoration: none;
    font-weight: 600;
}

.map-popup-link:hover {
    text-decoration: underline;
}

.trip-map-drive-label {
    background: rgba(255, 255, 255, 0.88);
    border: 1px solid #d6c9b5;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    color: #5b5346;
    padding: 2px 7px;
    white-space: nowrap;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
    font-family: inherit;
    pointer-events: none;
}
</style>
