import type * as Leaflet from 'leaflet';

/**
 * NamibWay's branded map pin — a terracotta teardrop, used anywhere a single
 * exact-location marker is needed (e.g. the owner location picker).
 * Multi-marker maps (ExploreMap, TripMap) use their own divIcon styles
 * (dot / numbered stop) since they need to convey count or sequence.
 */
export function createBrandPinIcon(L: typeof Leaflet): Leaflet.DivIcon {
    return L.divIcon({
        className: '',
        html: '<div class="brand-map-pin"></div>',
        iconSize: [30, 42],
        iconAnchor: [15, 40],
        popupAnchor: [0, -36],
    });
}
