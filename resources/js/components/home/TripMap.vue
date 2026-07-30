<script setup lang="ts">
import type { Map as LeafletMap, Marker, Polyline } from 'leaflet';
import { onMounted, onUnmounted, watch } from 'vue';
import type { RegionCoords } from '@/lib/kaia-client';
import type { ItineraryDay, ItineraryVariant } from '@/lib/kaia-types';

const props = defineProps<{
    variant: ItineraryVariant;
    regionCoords: Record<string, RegionCoords>;
    mapId: string;
}>();

let map: LeafletMap | null = null;
let markers: Marker[] = [];
let routeLine: Polyline | null = null;

const NAMIBIA_CENTER: [number, number] = [-22.0, 17.5];
const DEFAULT_ZOOM = 5;

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
        center: NAMIBIA_CENTER,
        zoom: DEFAULT_ZOOM,
        zoomControl: true,
        scrollWheelZoom: false,
    });

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

    // Fall back to region-level coordinates
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
            day: number;
            accommodationName: string | null;
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
                label: day.location,
                day: day.day,
                accommodationName: day.accommodation?.name ?? null,
            });
        }

        if (waypoints.length === 0) {
            return;
        }

        const latlngs = waypoints.map((w) => w.latlng);

        routeLine = L.polyline(latlngs, {
            color: '#c0533a',
            weight: 2.5,
            dashArray: '6 5',
            opacity: 0.85,
        }).addTo(map!);

        waypoints.forEach((wp) => {
            const icon = L.divIcon({
                className: '',
                html: `<div class="trip-map-marker">${wp.day}</div>`,
                iconSize: [28, 28],
                iconAnchor: [14, 14],
            });

            const popupLines = [
                `<div class="map-popup">`,
                `<div class="map-popup-day">Day ${wp.day}</div>`,
                `<div class="map-popup-location">${wp.label}</div>`,
                wp.accommodationName
                    ? `<div class="map-popup-accommodation">${wp.accommodationName}</div>`
                    : '',
                `</div>`,
            ].join('');

            const marker = L.marker(wp.latlng, { icon })
                .addTo(map!)
                .bindPopup(popupLines, { maxWidth: 200 });

            marker.on('mouseover', () => marker.openPopup());

            markers.push(marker);
        });

        if (waypoints.length === 1) {
            map!.setView(waypoints[0].latlng, 8);
        } else {
            map!.fitBounds(L.latLngBounds(latlngs), { padding: [48, 48] });
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
    <div class="trip-map-wrapper">
        <div :id="mapId" class="trip-map-container" />
    </div>
</template>

<style scoped>
.trip-map-wrapper {
    margin: 20px 0 4px;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--sand-dark, #d6c9b5);
}

.trip-map-container {
    height: 320px;
    width: 100%;
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
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.trip-map-marker:hover {
    transform: scale(1.2);
    box-shadow: 0 4px 12px rgba(192, 83, 58, 0.5);
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

.map-popup-accommodation {
    font-size: 11px;
    color: #6b5f54;
}
</style>
