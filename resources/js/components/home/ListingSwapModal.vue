<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type {
    ListingSearchMeta,
    ListingSearchResult,
    ListingSearchSort,
} from '@/lib/kaia-client';
import { searchListings } from '@/lib/kaia-client';
import type { ItineraryListingRef } from '@/lib/kaia-types';
import { onImageError, thumbAttrs } from '@/lib/media';
import { formatPriceWithUnit } from '@/lib/price-unit';
import { show } from '@/routes/listings';

const props = defineProps<{
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    title: string;
    excludeId?: number | null;
    // The day's own city ("same city") — preselected but not locked, so the
    // traveler can widen the search themselves ("or the surrounding area")
    // instead of the panel silently doing it for them.
    defaultCity?: string | null;
    cities: string[];
}>();

const emit = defineEmits<{
    (e: 'select', alt: ItineraryListingRef): void;
    (e: 'close'): void;
}>();

const { t } = useI18n();

const keyword = ref('');
const city = ref(props.defaultCity ?? '');
const budget = ref('');
const minRating = ref('');
const maxDistanceKm = ref('');
const sort = ref<ListingSearchSort>('featured');
const filtersOpen = ref(false);

// Vehicles are either self-drive rentals or guided tours with a
// driver-guide included — different enough that mixing them in one
// undifferentiated list would bury the distinction the traveler most
// needs to make. Defaults to self-drive, the more common request.
const vehicleCategory = ref<'self_drive' | 'guided_tour'>('self_drive');

// Distance is always relative to the day's own city, regardless of which
// city the traveler is currently filtering by — "how far is this from where
// I'll actually be" doesn't change just because they widened the search.
const hasReferencePoint = !!props.defaultCity;

const results = ref<ListingSearchResult[]>([]);
const meta = ref<ListingSearchMeta>({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 12,
});
const loading = ref(true);
const loadingMore = ref(false);

let debounceHandle: ReturnType<typeof setTimeout> | null = null;

async function runSearch(page = 1, append = false) {
    if (append) {
        loadingMore.value = true;
    } else {
        loading.value = true;
    }

    try {
        const response = await searchListings({
            type: props.type,
            vehicleCategory:
                props.type === 'vehicle' ? vehicleCategory.value : undefined,
            city: city.value || undefined,
            keyword: keyword.value || undefined,
            budget: budget.value || undefined,
            minRating: minRating.value || undefined,
            referenceCity: props.defaultCity || undefined,
            maxDistanceKm: maxDistanceKm.value || undefined,
            sort: sort.value,
            excludeId: props.excludeId ?? undefined,
            page,
        });

        results.value = append
            ? [...results.value, ...response.data]
            : response.data;
        meta.value = response.meta;
    } catch {
        if (!append) {
            results.value = [];
        }
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }
}

function loadMore() {
    if (meta.value.current_page < meta.value.last_page) {
        runSearch(meta.value.current_page + 1, true);
    }
}

// Keyword typing is debounced; select-driven filters (city/budget/rating)
// react immediately since there's no risk of firing a request per keystroke.
watch(keyword, () => {
    if (debounceHandle) {
        clearTimeout(debounceHandle);
    }

    debounceHandle = setTimeout(() => runSearch(1, false), 350);
});

watch([city, budget, minRating, maxDistanceKm, sort, vehicleCategory], () =>
    runSearch(1, false),
);

function detailUrl(slug: string): string {
    return show({ listing: slug }).url;
}

function priceLabel(result: ListingSearchResult): string | null {
    return formatPriceWithUnit(result.price_from, result.price_unit, t);
}

function select(result: ListingSearchResult) {
    emit('select', {
        id: result.id,
        slug: result.slug,
        name: result.name,
        type: result.type,
        vehicle_category: result.vehicle_category,
        price_from: result.price_from,
        price_currency: result.price_currency,
        // Same reasoning as the coordinates below: dropping this on the way
        // into the plan would leave the swapped-in listing's price unqualified
        // and mis-counted in the stage total, even though the catalog knows.
        price_unit: result.price_unit,
        duration_minutes: result.duration_minutes,
        // Without coordinates the swapped-in listing drops off the trip map.
        lat: result.latitude,
        lng: result.longitude,
        image: result.image,
        gallery: result.gallery,
        city: result.city,
        region: result.region,
        short_description: result.short_description,
        rating: result.rating,
        rating_count: result.rating_count,
    });
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => {
    runSearch(1, false);
    window.addEventListener('keydown', onKeydown);
});
onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);

    if (debounceHandle) {
        clearTimeout(debounceHandle);
    }
});
</script>

<template>
    <div
        class="swap-modal-backdrop"
        role="dialog"
        aria-modal="true"
        @click.self="emit('close')"
    >
        <div class="swap-modal-box">
            <div class="swap-modal-header">
                <h3>{{ title }}</h3>
                <button
                    class="swap-modal-close"
                    :aria-label="t('listingPreview.close')"
                    @click="emit('close')"
                >
                    ×
                </button>
            </div>

            <div v-if="type === 'vehicle'" class="swap-modal-vehicle-tabs">
                <button
                    type="button"
                    class="swap-modal-vehicle-tab"
                    :class="{
                        'swap-modal-vehicle-tab--active':
                            vehicleCategory === 'self_drive',
                    }"
                    @click="vehicleCategory = 'self_drive'"
                >
                    {{ t('vehicle.category.self_drive') }}
                </button>
                <button
                    type="button"
                    class="swap-modal-vehicle-tab"
                    :class="{
                        'swap-modal-vehicle-tab--active':
                            vehicleCategory === 'guided_tour',
                    }"
                    @click="vehicleCategory = 'guided_tour'"
                >
                    {{ t('vehicle.category.guided_tour') }}
                </button>
            </div>

            <div class="swap-modal-filters">
                <input
                    v-model="keyword"
                    type="text"
                    class="swap-modal-search"
                    :placeholder="t('explore.filters.keyword')"
                />
                <select v-model="city" class="swap-modal-select">
                    <option value="">
                        {{ t('explore.filters.allCities') }}
                    </option>
                    <option v-for="c in cities" :key="c" :value="c">
                        {{ c }}
                    </option>
                </select>
                <select v-model="sort" class="swap-modal-select">
                    <option value="featured">
                        {{ t('explore.results.sortFeatured') }}
                    </option>
                    <option value="price_asc">
                        {{ t('explore.results.sortPriceAsc') }}
                    </option>
                    <option value="price_desc">
                        {{ t('explore.results.sortPriceDesc') }}
                    </option>
                    <option value="rating">
                        {{ t('explore.results.sortRating') }}
                    </option>
                    <option value="popularity">
                        {{ t('explore.results.sortPopularity') }}
                    </option>
                    <option v-if="hasReferencePoint" value="distance">
                        {{ t('explore.results.sortDistance') }}
                    </option>
                </select>
                <button
                    type="button"
                    class="swap-modal-more-filters"
                    @click="filtersOpen = !filtersOpen"
                >
                    {{
                        filtersOpen
                            ? t('explore.filters.hideFilters')
                            : t('explore.filters.moreFilters')
                    }}
                </button>
            </div>

            <div
                v-if="filtersOpen"
                class="swap-modal-filters swap-modal-filters--extra"
            >
                <select v-model="budget" class="swap-modal-select">
                    <option value="">
                        {{ t('explore.filters.anyBudget') }}
                    </option>
                    <option value="budget">
                        {{ t('explore.filters.budget') }}
                    </option>
                    <option value="mid-range">
                        {{ t('explore.filters.midRange') }}
                    </option>
                    <option value="premium">
                        {{ t('explore.filters.premium') }}
                    </option>
                </select>
                <select v-model="minRating" class="swap-modal-select">
                    <option value="">
                        {{ t('explore.filters.anyRating') }}
                    </option>
                    <option value="4.5">
                        {{ t('explore.filters.rating45') }}
                    </option>
                    <option value="4">
                        {{ t('explore.filters.rating4') }}
                    </option>
                    <option value="3">
                        {{ t('explore.filters.rating3') }}
                    </option>
                </select>
                <select
                    v-if="hasReferencePoint"
                    v-model="maxDistanceKm"
                    class="swap-modal-select"
                >
                    <option value="">
                        {{ t('explore.filters.anyDistance') }}
                    </option>
                    <option value="10">
                        {{ t('explore.filters.distance10') }}
                    </option>
                    <option value="25">
                        {{ t('explore.filters.distance25') }}
                    </option>
                    <option value="50">
                        {{ t('explore.filters.distance50') }}
                    </option>
                </select>
            </div>

            <div class="swap-modal-results">
                <div v-if="loading" class="swap-modal-loading">
                    {{ t('explore.results.searching') }}
                </div>
                <template v-else-if="results.length">
                    <button
                        v-for="result in results"
                        :key="result.id"
                        type="button"
                        class="swap-modal-item"
                        @click="select(result)"
                    >
                        <img
                            v-if="result.image"
                            v-bind="thumbAttrs(result.image, 48)"
                            :alt="result.name"
                            class="swap-modal-item-thumb"
                            width="48"
                            height="48"
                            loading="lazy"
                            decoding="async"
                            @error="onImageError($event, props.type)"
                        />
                        <span class="swap-modal-item-body">
                            <span class="swap-modal-item-name">{{
                                result.name
                            }}</span>
                            <span
                                v-if="result.city || result.region"
                                class="swap-modal-item-location"
                                >{{ result.city || result.region }}</span
                            >
                            <span class="swap-modal-item-meta">
                                <span
                                    v-if="result.vehicle_category"
                                    class="swap-modal-item-badge swap-modal-item-badge--vehicle"
                                    :class="`swap-modal-item-badge--${result.vehicle_category}`"
                                    >{{
                                        t(
                                            `vehicle.category.${result.vehicle_category}`,
                                        )
                                    }}</span
                                >
                                <span
                                    v-if="result.is_featured"
                                    class="swap-modal-item-badge"
                                    >{{ t('listing.popular') }}</span
                                >
                                <span
                                    v-if="result.rating !== null"
                                    class="swap-modal-item-rating"
                                    >★ {{ result.rating.toFixed(1)
                                    }}<template v-if="result.rating_count">
                                        ({{ result.rating_count }})</template
                                    ></span
                                >
                                <span
                                    v-if="result.distance_km !== null"
                                    class="swap-modal-item-distance"
                                    >{{
                                        Math.round(result.distance_km)
                                    }}
                                    km</span
                                >
                            </span>
                            <span
                                v-if="result.highlights.length"
                                class="swap-modal-item-tags"
                            >
                                <span
                                    v-for="h in result.highlights.slice(0, 3)"
                                    :key="h"
                                    class="swap-modal-item-tag"
                                    >{{ h }}</span
                                >
                            </span>
                        </span>
                        <!-- With the unit where it's recorded: this list is
                             where a traveler compares prices against each
                             other, which two rates quoted per different things
                             make meaningless. -->
                        <span
                            v-if="priceLabel(result)"
                            class="swap-modal-item-price"
                            >{{ priceLabel(result) }}</span
                        >
                        <a
                            :href="detailUrl(result.slug)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="swap-modal-item-detail"
                            :title="t('itinerary.viewDetail')"
                            @click.stop
                            >↗</a
                        >
                        <span class="swap-modal-item-use">{{
                            t('itinerary.useThis')
                        }}</span>
                    </button>

                    <button
                        v-if="meta.current_page < meta.last_page"
                        type="button"
                        class="swap-modal-load-more"
                        :disabled="loadingMore"
                        @click="loadMore"
                    >
                        {{ t('explore.results.loadMore') }}
                    </button>
                </template>
                <div v-else class="swap-modal-empty">
                    {{ t('explore.results.noResults') }}
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.swap-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(16, 26, 48, 0.55);
    z-index: 260;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.swap-modal-box {
    background: var(--paper, #fbf8f1);
    border-radius: 16px;
    width: 100%;
    max-width: 560px;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 24px 60px rgba(16, 26, 48, 0.3);
}

.swap-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 20px 4px;
}

.swap-modal-header h3 {
    font-family: 'Fraunces', serif;
    font-size: 19px;
    font-weight: 500;
    margin: 0;
    color: var(--ink, #241c15);
}

.swap-modal-close {
    width: 32px;
    height: 32px;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(16, 26, 48, 0.08);
    color: var(--ink, #241c15);
    border: none;
    border-radius: 999px;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
}

.swap-modal-close:hover {
    background: rgba(16, 26, 48, 0.16);
}

.swap-modal-vehicle-tabs {
    display: flex;
    gap: 8px;
    padding: 10px 20px 0;
}

.swap-modal-vehicle-tab {
    flex: 1 1 0;
    padding: 9px 10px;
    border: 1px solid var(--sand-dark, #d8cdb4);
    border-radius: 9px;
    background: #fff;
    color: var(--ink, #241c15);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
}

.swap-modal-vehicle-tab--active {
    background: var(--night, #1b2a4a);
    border-color: var(--night, #1b2a4a);
    color: var(--paper, #fbf8f1);
}

.swap-modal-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 10px 20px;
}

.swap-modal-filters--extra {
    padding-top: 0;
}

.swap-modal-search {
    flex: 1 1 160px;
    min-width: 0;
    padding: 9px 12px;
    border: 1px solid var(--sand-dark, #d8cdb4);
    border-radius: 9px;
    font-size: 13.5px;
    background: #fff;
}

.swap-modal-select {
    flex: 1 1 140px;
    min-width: 0;
    padding: 9px 10px;
    border: 1px solid var(--sand-dark, #d8cdb4);
    border-radius: 9px;
    font-size: 13.5px;
    background: #fff;
}

.swap-modal-more-filters {
    flex: 0 0 auto;
    padding: 9px 10px;
    border: none;
    background: none;
    color: var(--rust, #b5651d);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.swap-modal-results {
    overflow-y: auto;
    padding: 4px 20px 20px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.swap-modal-loading,
.swap-modal-empty {
    padding: 40px 12px;
    text-align: center;
    color: #5b5346;
    font-size: 14px;
}

.swap-modal-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 8px;
    border: 1px solid var(--sand-dark, #d8cdb4);
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    text-align: left;
}

.swap-modal-item:hover {
    border-color: var(--rust, #b5651d);
}

.swap-modal-item-thumb {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    object-fit: cover;
    flex-shrink: 0;
}

.swap-modal-item-body {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1 1 auto;
}

.swap-modal-item-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--ink, #241c15);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.swap-modal-item-location {
    font-size: 12px;
    color: #8a7f68;
}

.swap-modal-item-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.swap-modal-item-rating,
.swap-modal-item-distance {
    font-size: 12px;
    color: #8a7f68;
}

.swap-modal-item-badge {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--rust-dark, #8a3d24);
    background: var(--rust-light, #f3e2d8);
    border-radius: 999px;
    padding: 2px 7px;
}

.swap-modal-item-badge--self_drive {
    color: var(--rust-dark, #8a3d24);
    background: var(--rust-light, #f3e2d8);
}

.swap-modal-item-badge--guided_tour {
    color: var(--sage, #4c5d46);
    background: var(--sage-light, #dce4d6);
}

.swap-modal-item-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 2px;
}

.swap-modal-item-tag {
    font-size: 10.5px;
    color: #7a6f63;
    background: var(--sand, #f2ead9);
    border-radius: 5px;
    padding: 1px 6px;
}

.swap-modal-item-price {
    font-size: 13px;
    font-weight: 600;
    color: var(--ink, #241c15);
    flex-shrink: 0;
}

.swap-modal-item-detail {
    flex-shrink: 0;
    color: var(--rust, #b5651d);
    font-size: 15px;
    text-decoration: none;
    padding: 0 2px;
}

.swap-modal-item-use {
    flex-shrink: 0;
    padding: 6px 10px;
    border-radius: 999px;
    background: var(--night, #1b2a4a);
    color: var(--paper, #fbf8f1);
    font-size: 12.5px;
    font-weight: 600;
}

.swap-modal-load-more {
    align-self: center;
    margin-top: 6px;
    padding: 9px 18px;
    border: 1px solid var(--sand-dark, #d8cdb4);
    border-radius: 999px;
    background: #fff;
    font-size: 13px;
    font-weight: 600;
    color: var(--ink, #241c15);
    cursor: pointer;
}

.swap-modal-load-more:disabled {
    opacity: 0.6;
    cursor: default;
}

@media (max-width: 640px) {
    .swap-modal-backdrop {
        padding: 0;
        align-items: flex-end;
    }

    .swap-modal-box {
        max-width: 100%;
        max-height: 92vh;
        border-radius: 16px 16px 0 0;
    }
}
</style>
