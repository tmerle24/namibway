<script setup lang="ts">
import { onMounted, onUnmounted, watch } from 'vue';
import type { Map as LeafletMap, Marker, Polyline } from 'leaflet';
import type { ItineraryVariant } from '@/lib/kaia-types';
import type { RegionCoords } from '@/lib/kaia-client';

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
    delete (L.Icon.Default.prototype as unknown as Record<string, unknown>)._getIconUrl;
    L.Icon.Default.mergeOptions({
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
    });

    map = L.map(containerId, {
        center: NAMIBIA_CENTER,
        zoom: DEFAULT_ZOOM,
        zoomControl: true,
        scrollWheelZoom: false,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 18,
    }).addTo(map);

    renderRoute();
}

function renderRoute() {
    if (!map) return;

    // Lazy-import to avoid SSR issues
    import('leaflet').then(({ default: L }) => {
        // Clear previous layers
        markers.forEach((m) => m.remove());
        markers = [];
        if (routeLine) {
            routeLine.remove();
            routeLine = null;
        }

        // Collect waypoints in day order (deduplicated consecutive)
        const waypoints: Array<{ latlng: [number, number]; label: string; day: number }> = [];
        let prevLocation = '';

        for (const day of props.variant.days) {
            const key = day.location?.toLowerCase().trim();
            if (!key || key === prevLocation) continue;
            const coords = props.regionCoords[key];
            if (!coords) continue;
            waypoints.push({ latlng: [coords.lat, coords.lng], label: day.location, day: day.day });
            prevLocation = key;
        }

        if (waypoints.length === 0) return;

        // Draw dashed route line
        const latlngs = waypoints.map((w) => w.latlng);
        routeLine = L.polyline(latlngs, {
            color: '#c0533a',
            weight: 2.5,
            dashArray: '6 5',
            opacity: 0.8,
        }).addTo(map!);

        // Draw numbered markers
        waypoints.forEach((wp, index) => {
            const icon = L.divIcon({
                className: '',
                html: `<div class="trip-map-marker">${index + 1}</div>`,
                iconSize: [28, 28],
                iconAnchor: [14, 14],
            });

            const marker = L.marker(wp.latlng, { icon })
                .addTo(map!)
                .bindPopup(`<strong>${wp.label}</strong><br>Day ${wp.day}`);

            markers.push(marker);
        });

        // Fit map to route with padding
        if (waypoints.length === 1) {
            map!.setView(waypoints[0].latlng, 7);
        } else {
            map!.fitBounds(L.latLngBounds(latlngs), { padding: [40, 40] });
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

watch(() => [props.variant, props.regionCoords] as const, renderRoute, { deep: true });
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
}
</style>
