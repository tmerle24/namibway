<script setup lang="ts">
import type * as Leaflet from 'leaflet';
import type { Map as LeafletMap, Marker } from 'leaflet';
import { onMounted, onUnmounted, watch } from 'vue';
import { createBrandPinIcon } from '@/lib/map-icons';

const props = defineProps<{
    mapId: string;
    latitude: number | null;
    longitude: number | null;
}>();

const emit = defineEmits<{
    'update:latitude': [number];
    'update:longitude': [number];
}>();

const NAMIBIA_CENTER: [number, number] = [-22.0, 17.5];
const DEFAULT_ZOOM = 5;
const PIN_ZOOM = 11;

let map: LeafletMap | null = null;
let marker: Marker | null = null;

function currentLatLng(): [number, number] {
    return props.latitude !== null && props.longitude !== null
        ? [props.latitude, props.longitude]
        : NAMIBIA_CENTER;
}

async function initMap() {
    const L = (await import('leaflet')).default;
    await import('leaflet/dist/leaflet.css');

    const hasLocation = props.latitude !== null && props.longitude !== null;

    map = L.map(props.mapId, {
        center: currentLatLng(),
        zoom: hasLocation ? PIN_ZOOM : DEFAULT_ZOOM,
        zoomControl: true,
        scrollWheelZoom: false,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution:
            '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 18,
    }).addTo(map);

    if (hasLocation) {
        placeMarker(currentLatLng(), L);
    }

    map.on('click', (e: { latlng: { lat: number; lng: number } }) => {
        placeMarker([e.latlng.lat, e.latlng.lng], L);
        emit('update:latitude', round(e.latlng.lat));
        emit('update:longitude', round(e.latlng.lng));
    });
}

function placeMarker(latlng: [number, number], L: typeof Leaflet) {
    if (!map) {
        return;
    }

    if (marker) {
        marker.setLatLng(latlng);
    } else {
        marker = L.marker(latlng, {
            draggable: true,
            icon: createBrandPinIcon(L),
        }).addTo(map);
        marker.on('dragend', () => {
            const pos = marker!.getLatLng();
            emit('update:latitude', round(pos.lat));
            emit('update:longitude', round(pos.lng));
        });
    }
}

function round(value: number): number {
    return Math.round(value * 1e6) / 1e6;
}

// Keep the pin in sync when the number inputs are edited by hand instead of
// by clicking/dragging on the map.
watch(
    () => [props.latitude, props.longitude],
    ([lat, lng]) => {
        if (!map || lat === null || lng === null) {
            return;
        }

        import('leaflet').then(({ default: L }) => {
            placeMarker([lat, lng], L);
        });
    },
);

onMounted(() => {
    initMap();
});

onUnmounted(() => {
    map?.remove();
    map = null;
    marker = null;
});
</script>

<template>
    <div class="location-picker-wrapper">
        <div :id="mapId" class="location-picker-map" />
        <p class="location-picker-hint">
            Click the map, or drag the pin, to set this listing's location.
        </p>
    </div>
</template>

<style scoped>
.location-picker-wrapper {
    position: relative;
    z-index: 0;
}

.location-picker-map {
    height: 260px;
    width: 100%;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--sand-dark, #d6c9b5);
    background: #f0ece4;
    cursor: crosshair;
}

.location-picker-hint {
    margin: 6px 0 0;
    font-size: 12px;
    color: #6b6355;
}
</style>

<style>
.brand-map-pin {
    width: 30px;
    height: 42px;
    cursor: grab;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='30' height='42' viewBox='0 0 30 42'%3E%3Cpath d='M15 1C7.268 1 1 7.268 1 15c0 10.5 14 25 14 25s14-14.5 14-25C29 7.268 22.732 1 15 1z' fill='%23c0533a' stroke='%23fff' stroke-width='2'/%3E%3Ccircle cx='15' cy='15' r='5.5' fill='%23fff'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-size: contain;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.35));
    transition: transform 0.15s ease;
}

.brand-map-pin:active {
    cursor: grabbing;
    transform: scale(1.08);
}
</style>
