<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { Map as LeafletMap, Marker, MarkerClusterGroup } from 'leaflet';
import { onMounted, onUnmounted, watch } from 'vue';
import { show } from '@/routes/listings';

export interface ExploreMapMarker {
    title: string;
    typeLabel: string;
    image: string;
    slug: string | null;
    address?: string | null;
    latitude: number;
    longitude: number;
}

const props = defineProps<{
    markers: ExploreMapMarker[];
    mapId: string;
    backQuery?: Record<string, string>;
}>();

const emit = defineEmits<{ navigate: [] }>();

let map: LeafletMap | null = null;
let clusterGroup: MarkerClusterGroup | null = null;
let markerLayers: Marker[] = [];
let destroyed = false;

const NAMIBIA_CENTER: [number, number] = [-22.0, 17.5];
const DEFAULT_ZOOM = 5;

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

async function initMap() {
    const L = (await import('leaflet')).default;
    await import('leaflet/dist/leaflet.css');
    await import('leaflet.markercluster');
    await import('leaflet.markercluster/dist/MarkerCluster.css');

    if (destroyed) {
        return;
    }

    map = L.map(props.mapId, {
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

    renderMarkers();
}

function renderMarkers() {
    if (!map) {
        return;
    }

    Promise.all([import('leaflet'), import('leaflet.markercluster')]).then(
        ([{ default: L }]) => {
            if (!map) {
                return;
            }

            markerLayers.forEach((m) => m.remove());
            markerLayers = [];

            if (clusterGroup) {
                clusterGroup.remove();
                clusterGroup = null;
            }

            if (props.markers.length === 0) {
                return;
            }

            clusterGroup = L.markerClusterGroup({
                showCoverageOnHover: false,
                maxClusterRadius: 50,
                iconCreateFunction: (cluster) => {
                    const count = cluster.getChildCount();
                    const size = count >= 25 ? 40 : count >= 10 ? 34 : 28;

                    return L.divIcon({
                        className: '',
                        html: `<div class="explore-map-cluster" style="width:${size}px;height:${size}px">${count}</div>`,
                        iconSize: [size, size],
                        iconAnchor: [size / 2, size / 2],
                    });
                },
            });

            props.markers.forEach((item) => {
                const icon = L.divIcon({
                    className: '',
                    html: '<div class="explore-map-marker"></div>',
                    iconSize: [16, 16],
                    iconAnchor: [8, 8],
                });

                const popupLines = [
                    '<div class="explore-map-popup">',
                    `<img src="${escapeHtml(item.image)}" alt="" class="explore-map-popup-img" />`,
                    '<div class="explore-map-popup-body">',
                    `<div class="explore-map-popup-tag">${escapeHtml(item.typeLabel)}</div>`,
                    `<div class="explore-map-popup-title">${escapeHtml(item.title)}</div>`,
                    item.address
                        ? `<div class="explore-map-popup-address">${escapeHtml(item.address)}</div>`
                        : '',
                    '</div>',
                    '</div>',
                ].join('');

                const marker = L.marker([item.latitude, item.longitude], {
                    icon,
                }).bindPopup(popupLines, { maxWidth: 260 });

                marker.on('mouseover', () => marker.openPopup());

                if (item.slug) {
                    const slug = item.slug;
                    const goToListing = () => {
                        emit('navigate');
                        const query = props.backQuery;
                        const url = show(
                            { listing: slug },
                            query && Object.keys(query).length > 0
                                ? { query }
                                : undefined,
                        ).url;
                        router.visit(url);
                    };

                    marker.on('click', goToListing);
                    marker.on('popupopen', () => {
                        const el = marker.getPopup()?.getElement();
                        el?.querySelector(
                            '.explore-map-popup',
                        )?.addEventListener('click', goToListing);
                    });
                }

                markerLayers.push(marker);
                clusterGroup!.addLayer(marker);
            });

            map!.addLayer(clusterGroup!);

            const latlngs = props.markers.map(
                (item) => [item.latitude, item.longitude] as [number, number],
            );

            if (latlngs.length === 1) {
                map!.setView(latlngs[0], 10);
            } else {
                map!.fitBounds(L.latLngBounds(latlngs), { padding: [32, 32] });
            }
        },
    );
}

onMounted(() => {
    initMap();
});

onUnmounted(() => {
    destroyed = true;
    map?.remove();
    map = null;
});

watch(() => props.markers, renderMarkers);
</script>

<template>
    <div class="explore-map-wrapper">
        <div :id="mapId" class="explore-map-container" />
    </div>
</template>

<style scoped>
.explore-map-wrapper {
    position: relative;
    z-index: 0;
    margin: 20px 0 32px;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--sand-dark, #d6c9b5);
}

.explore-map-container {
    height: 360px;
    width: 100%;
    background: #f0ece4;
}
</style>

<style>
.explore-map-marker {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #c0533a;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
    cursor: pointer;
    transition:
        transform 0.15s ease,
        box-shadow 0.15s ease;
}

.explore-map-marker:hover {
    transform: scale(1.3);
    box-shadow: 0 2px 8px rgba(192, 83, 58, 0.5);
}

.explore-map-cluster {
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #c0533a;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
    cursor: pointer;
    transition: transform 0.15s ease;
}

.explore-map-cluster:hover {
    transform: scale(1.08);
}

.explore-map-popup {
    display: flex;
    gap: 8px;
    align-items: center;
    font-family: inherit;
    min-width: 160px;
    cursor: pointer;
}

.explore-map-popup-img {
    width: 48px;
    height: 48px;
    border-radius: 6px;
    object-fit: cover;
    flex-shrink: 0;
}

.explore-map-popup-tag {
    font-size: 10px;
    font-weight: 600;
    color: #c0533a;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 2px;
}

.explore-map-popup-title {
    font-size: 13px;
    font-weight: 700;
    color: #2c2521;
}

.explore-map-popup-address {
    font-size: 11px;
    color: #6b6255;
    margin-top: 2px;
}
</style>
