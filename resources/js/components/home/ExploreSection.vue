<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import 'flatpickr/dist/flatpickr.min.css';
import flatpickr from 'flatpickr';
import type { Instance as FlatpickrInstance } from 'flatpickr/dist/types/instance';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { consumeExploreScroll, saveExploreScroll } from '@/lib/explore-scroll';
import type { SearchIntent } from '@/lib/kaia-types';
import { onImageError, thumbAttrs } from '@/lib/media';
import type { PriceUnit } from '@/lib/price-unit';
import { formatPriceWithUnit } from '@/lib/price-unit';
import { show } from '@/routes/listings';
import ExploreMap from './ExploreMap.vue';
import type { ExploreMapMarker } from './ExploreMap.vue';
import ShortlistBar from './ShortlistBar.vue';

interface Listing {
    id: number;
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    name: string;
    slug: string;
    description: string | null;
    image: string | null;
    region: string | null;
    city: string | null;
    latitude: number | null;
    longitude: number | null;
    price_from: string | null;
    price_currency: string;
    rating: number | null;
    rating_count: number | null;
}

interface Destination {
    name: string;
    slug: string;
    blurb: string | null;
    image: string | null;
    region_name: string;
}

const props = defineProps<{
    listings: Listing[];
    destinations: Destination[];
    featuredPick: Listing | null;
    triggerSearch?: SearchIntent | null;
}>();

const { t } = useI18n();

type Budget = 'budget' | 'mid-range' | 'premium' | null;
type RowKey =
    'accommodation' | 'activity' | 'restaurant' | 'vehicle' | 'region';

interface IdeaCard {
    id: number | null;
    title: string;
    description: string;
    region: string | null;
    city: string | null;
    budget: Budget;
    image: string;
    slug: string | null;
    latitude: number | null;
    longitude: number | null;
    rating: number | null;
}

interface IdeaRow {
    key: RowKey;
    bg: string;
    items: IdeaCard[];
}

interface SearchListing {
    id: number;
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    name: string;
    slug: string;
    description: string | null;
    image: string | null;
    region: string | null;
    city: string | null;
    price_from: string | null;
    price_currency: string;
    price_unit: PriceUnit | null;
    rating: number | null;
    rating_count: number | null;
    accepts_inquiries: boolean;
}

interface SearchMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

const CATEGORY_IMAGES: Record<Listing['type'], string[]> = {
    accommodation: [
        '/images/explore/accommodation-1.jpg',
        '/images/explore/accommodation-2.jpg',
        '/images/explore/accommodation-3.jpg',
        '/images/explore/accommodation-4.jpg',
    ],
    activity: [
        '/images/explore/activity-1.jpg',
        '/images/explore/activity-2.jpg',
        '/images/explore/activity-3.jpg',
        '/images/explore/activity-4.jpg',
    ],
    restaurant: [
        '/images/explore/restaurant-1.jpg',
        '/images/explore/restaurant-2.jpg',
        '/images/explore/restaurant-3.jpg',
        '/images/explore/restaurant-4.jpg',
    ],
    vehicle: [
        '/images/explore/vehicle-1.jpg',
        '/images/explore/vehicle-2.jpg',
        '/images/explore/vehicle-3.jpg',
        '/images/explore/vehicle-4.jpg',
    ],
};

function budgetBucket(price: string | null): Budget {
    if (price === null) {
        return null;
    }

    const value = parseFloat(price);

    if (Number.isNaN(value)) {
        return null;
    }

    if (value < 150) {
        return 'budget';
    }

    if (value <= 400) {
        return 'mid-range';
    }

    return 'premium';
}

// `description` is stored HTML-escaped (see Listing::setDescriptionAttribute) so it
// can be rendered as raw HTML on the listing detail page. These cards render it as
// plain text instead, so it must be decoded first or entities like "&#039;" show up
// literally (Vue's text interpolation escapes them a second time).
function decodeEntities(text: string): string {
    const el = document.createElement('textarea');
    el.innerHTML = text;

    return el.value;
}

function priceLabel(item: SearchListing): string | null {
    return formatPriceWithUnit(item.price_from, item.price_unit, t);
}

function truncate(text: string | null, length = 120): string {
    if (!text) {
        return '';
    }

    const decoded = decodeEntities(text);

    return decoded.length > length
        ? decoded.slice(0, length).trim() + '…'
        : decoded;
}

const ROW_BG: Record<RowKey, string> = {
    accommodation: 'rust-light',
    activity: 'sage-light',
    restaurant: 'sand',
    vehicle: 'gold',
    region: 'sand-dark',
};

const ideaRows = computed<IdeaRow[]>(() => {
    const byType: Record<Listing['type'], IdeaCard[]> = {
        accommodation: [],
        activity: [],
        restaurant: [],
        vehicle: [],
    };

    for (const listing of props.listings) {
        const images = CATEGORY_IMAGES[listing.type];
        byType[listing.type].push({
            id: listing.id,
            title: listing.name,
            description: truncate(listing.description),
            region: listing.region,
            city: listing.city,
            budget: budgetBucket(listing.price_from),
            image:
                listing.image ??
                images[byType[listing.type].length % images.length],
            slug: listing.slug,
            latitude: listing.latitude,
            longitude: listing.longitude,
            rating: listing.rating,
        });
    }

    const rows: IdeaRow[] = (Object.keys(byType) as Listing['type'][])
        .filter((type) => byType[type].length > 0)
        .map((type) => ({ key: type, bg: ROW_BG[type], items: byType[type] }));

    rows.push({
        key: 'region',
        bg: ROW_BG.region,
        items: props.destinations.map((destination) => ({
            id: null,
            title: destination.name,
            description: destination.blurb ?? '',
            region: destination.region_name,
            city: null,
            budget: null,
            image: destination.image ?? '/images/explore/region-khomas.jpg',
            slug: null,
            latitude: null,
            longitude: null,
            rating: null,
        })),
    });

    return rows;
});

const featuredPickImage = computed(() => {
    const pick = props.featuredPick;

    if (!pick) {
        return null;
    }

    return pick.image ?? CATEGORY_IMAGES[pick.type][0];
});

const availableRegions = computed(() => {
    const regions = new Set<string>();

    for (const row of ideaRows.value) {
        for (const item of row.items) {
            if (item.region) {
                regions.add(item.region);
            }
        }
    }

    return [...regions].sort();
});

// Only listings carry a real city (destination cards use the 'region' row
// and have no city of their own) — narrows to the selected region, if any.
const availableCities = computed(() => {
    const cities = new Set<string>();

    for (const row of ideaRows.value) {
        if (row.key === 'region') {
            continue;
        }

        for (const item of row.items) {
            if (
                item.city &&
                (!filterRegion.value || item.region === filterRegion.value)
            ) {
                cities.add(item.city);
            }
        }
    }

    return [...cities].sort();
});

// What the two selects actually offer. The lists above are derived from the
// inspiration rows, which are a slice of the catalog — so a filter arriving
// from outside (a listing detail page's city/region link, ?city= in the URL)
// can name a place that slice never mentions. Without folding the active
// value in, the select would render blank while the results below are very
// much filtered by it. Kept separate from availableCities so the
// strand-clearing watcher below still sees the region-derived list.
const withActive = (options: string[], active: string) =>
    active && !options.includes(active) ? [...options, active].sort() : options;

const regionOptions = computed(() =>
    withActive(availableRegions.value, filterRegion.value),
);

const cityOptions = computed(() =>
    withActive(availableCities.value, filterCity.value),
);

const filterCategory = ref('');
const filterRegion = ref('');
const filterCity = ref('');
const filterBudget = ref('');
const filterMinRating = ref('');
const filterKeyword = ref('');
const filterMoreOpen = ref(false);
const dateInput = ref<HTMLInputElement | null>(null);
const filterBar = ref<HTMLDivElement | null>(null);
let fp: FlatpickrInstance | null = null;

const searchMode = ref(false);
const mapOpen = ref(false);
const searchResults = ref<SearchListing[]>([]);
const searchMeta = ref<SearchMeta>({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 12,
});
const searchLoading = ref(false);
const sortBy = ref('featured');

async function performSearch(page = 1, append = false) {
    searchLoading.value = true;
    searchMode.value = true;

    const params = new URLSearchParams();

    if (filterCategory.value) {
        params.set('type', filterCategory.value);
    }

    if (filterRegion.value) {
        params.set('region', filterRegion.value);
    }

    if (filterCity.value) {
        params.set('city', filterCity.value);
    }

    if (filterKeyword.value) {
        params.set('keyword', filterKeyword.value);
    }

    if (filterBudget.value) {
        params.set('budget', filterBudget.value);
    }

    if (filterMinRating.value) {
        params.set('min_rating', filterMinRating.value);
    }

    params.set('sort', sortBy.value);
    params.set('page', String(page));

    try {
        const res = await fetch(`/listings/search?${params.toString()}`);
        const json = (await res.json()) as {
            data: SearchListing[];
            meta: SearchMeta;
        };

        if (append) {
            searchResults.value = [...searchResults.value, ...json.data];
        } else {
            searchResults.value = json.data;
        }

        searchMeta.value = json.meta;
    } catch {
        // silently ignore network errors
    } finally {
        searchLoading.value = false;
    }
}

function exitSearchMode() {
    searchMode.value = false;
    searchResults.value = [];
}

const hasActiveFilters = computed(
    () =>
        filterCategory.value !== '' ||
        filterRegion.value !== '' ||
        filterCity.value !== '' ||
        filterBudget.value !== '' ||
        filterMinRating.value !== '' ||
        filterKeyword.value !== '',
);

function clearFilters() {
    filterCategory.value = '';
    filterRegion.value = '';
    filterCity.value = '';
    filterBudget.value = '';
    filterMinRating.value = '';
    filterKeyword.value = '';
    fp?.clear();
    exitSearchMode();
}

function selectRegion(region: string) {
    filterCategory.value = '';
    filterRegion.value = region;
    filterCity.value = '';
    filterMoreOpen.value = true;
    filterBar.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function viewAllInRow(row: IdeaRow) {
    filterCategory.value = row.key;
    await performSearch(1);
    await nextTick();
    filterBar.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Picking a region can strand a previously-chosen city that belongs to a
// different one — drop it rather than silently filtering to zero results.
// Only when the inspiration rows actually place that city somewhere else,
// though: they cover a slice of the catalog, so a city missing from
// availableCities is just as likely to be one they never mention (e.g. an
// incoming ?region=&city= pair from a listing detail page), and dropping
// that one would quietly widen a filter the traveler asked for.
watch(filterRegion, () => {
    if (!filterCity.value || availableCities.value.includes(filterCity.value)) {
        return;
    }

    const placedElsewhere = ideaRows.value.some(
        (row) =>
            row.key !== 'region' &&
            row.items.some((item) => item.city === filterCity.value),
    );

    if (placedElsewhere) {
        filterCity.value = '';
    }
});

watch(
    () => props.triggerSearch,
    async (intent) => {
        if (!intent) {
            return;
        }

        filterCategory.value = intent.type ?? '';
        filterRegion.value = intent.region ?? '';
        filterCity.value = intent.city ?? '';
        filterKeyword.value = intent.keyword ?? '';
        filterBudget.value = intent.budget ?? '';
        filterMinRating.value = intent.min_rating ?? '';
        sortBy.value = intent.sort ?? 'featured';

        await performSearch(1);
        await nextTick();
        restoreScroll();
    },
);

function restoreScroll() {
    const y = consumeExploreScroll();

    if (y !== null) {
        window.scrollTo(0, y);
    }
}

const EXPLORE_QUERY_KEYS = [
    'type',
    'region',
    'city',
    'budget',
    'keyword',
    'min_rating',
    'sort',
];

function hasUrlFilters(): boolean {
    const params = new URLSearchParams(window.location.search);

    return EXPLORE_QUERY_KEYS.some((key) => params.get(key));
}

function saveScrollPosition() {
    saveExploreScroll();
}

const listingBackQuery = computed(() => {
    const query: Record<string, string> = {};

    if (filterCategory.value) {
        query.type = filterCategory.value;
    }

    if (filterRegion.value) {
        query.region = filterRegion.value;
    }

    if (filterCity.value) {
        query.city = filterCity.value;
    }

    if (filterBudget.value) {
        query.budget = filterBudget.value;
    }

    if (filterMinRating.value) {
        query.min_rating = filterMinRating.value;
    }

    if (filterKeyword.value) {
        query.keyword = filterKeyword.value;
    }

    if (searchMode.value && sortBy.value !== 'featured') {
        query.sort = sortBy.value;
    }

    return query;
});

function listingUrl(slug: string): string {
    const query = listingBackQuery.value;

    return show(
        { listing: slug },
        Object.keys(query).length > 0 ? { query } : undefined,
    ).url;
}

function onIdeaCardClick(row: IdeaRow, item: IdeaCard) {
    if (row.key === 'region' && item.region) {
        selectRegion(item.region);

        return;
    }

    if (item.slug) {
        saveScrollPosition();
    }
}

const SHORTLIST_KEY = 'namibway_shortlist';

function loadShortlist(): Map<number, string> {
    try {
        const raw = localStorage.getItem(SHORTLIST_KEY);

        if (raw) {
            return new Map(JSON.parse(raw) as [number, string][]);
        }
    } catch {
        // ignore corrupt storage
    }

    return new Map();
}

const shortlist = ref<Map<number, string>>(loadShortlist());

watch(
    shortlist,
    (map) => {
        try {
            localStorage.setItem(SHORTLIST_KEY, JSON.stringify([...map]));
        } catch {
            // ignore storage errors
        }
    },
    { deep: true },
);

function toggleShortlist(item: IdeaCard) {
    if (item.id === null) {
        return;
    }

    if (shortlist.value.has(item.id)) {
        shortlist.value.delete(item.id);
    } else {
        shortlist.value.set(item.id, item.title);
    }

    shortlist.value = new Map(shortlist.value);
}

function removeFromShortlist(id: number) {
    shortlist.value.delete(id);
    shortlist.value = new Map(shortlist.value);
}

function clearShortlist() {
    shortlist.value.clear();
    shortlist.value = new Map(shortlist.value);

    try {
        localStorage.removeItem(SHORTLIST_KEY);
    } catch {
        // ignore
    }
}

function toggleSearchShortlist(item: SearchListing) {
    if (shortlist.value.has(item.id)) {
        shortlist.value.delete(item.id);
    } else {
        shortlist.value.set(item.id, item.name);
    }

    shortlist.value = new Map(shortlist.value);
}

onMounted(() => {
    if (dateInput.value) {
        fp = flatpickr(dateInput.value, {
            mode: 'range',
            dateFormat: 'd M',
            minDate: 'today',
        });
    }

    // No incoming filters means no async search to wait for (see the
    // triggerSearch watcher above, which restores scroll once its results
    // are rendered instead) — the inspiration rows are already in the DOM.
    if (!hasUrlFilters()) {
        nextTick(() => restoreScroll());
    }
});

onUnmounted(() => {
    fp?.destroy();
});

function matches(row: IdeaRow, item: IdeaCard): boolean {
    if (filterCategory.value && row.key !== filterCategory.value) {
        return false;
    }

    if (filterRegion.value && item.region !== filterRegion.value) {
        return false;
    }

    if (filterCity.value && item.city !== filterCity.value) {
        return false;
    }

    if (
        filterBudget.value &&
        item.budget !== null &&
        item.budget !== filterBudget.value
    ) {
        return false;
    }

    if (filterBudget.value && item.budget === null && row.key !== 'region') {
        return false;
    }

    if (filterMinRating.value && row.key !== 'region') {
        const minRating = Number(filterMinRating.value);

        if (item.rating === null || item.rating < minRating) {
            return false;
        }
    }

    const keyword = filterKeyword.value.trim().toLowerCase();

    if (
        keyword &&
        !(
            item.title +
            ' ' +
            item.description +
            ' ' +
            (item.region ?? '') +
            ' ' +
            (item.city ?? '') +
            ' ' +
            t(`explore.rows.${row.key}`)
        )
            .toLowerCase()
            .includes(keyword)
    ) {
        return false;
    }

    return true;
}

// The idea-cards grid lays out 4 columns at the container's desktop width
// (see .idea-cards in kaia-home.css). Trimming each row down to a multiple
// of 4 keeps every row's last line full instead of a half-empty trailing
// row — unless that would empty the row out entirely, in which case we'd
// rather show a partial row than hide the category.
const GRID_COLUMNS = 4;

function floorToGridMultiple(items: IdeaCard[]): IdeaCard[] {
    const flooredCount = Math.floor(items.length / GRID_COLUMNS) * GRID_COLUMNS;

    return flooredCount > 0 ? items.slice(0, flooredCount) : items;
}

const visibleRows = computed(() =>
    ideaRows.value
        .map((row) => {
            const filtered = row.items.filter((item) => matches(row, item));

            // The region row is a fixed, curated list of destinations with
            // no "view all" escape hatch elsewhere — trimming it to a
            // multiple of 4 would silently hide whichever ones were added
            // last, so only the listing rows (backed by search) get floored.
            return {
                ...row,
                items:
                    row.key === 'region'
                        ? filtered
                        : floorToGridMultiple(filtered),
            };
        })
        .filter((row) => row.items.length > 0),
);

const hasResults = computed(() => visibleRows.value.length > 0);

const mapMarkers = computed<ExploreMapMarker[]>(() => {
    if (searchMode.value) {
        return [];
    }

    return visibleRows.value
        .filter((row) => row.key !== 'region')
        .flatMap((row) =>
            row.items
                .filter(
                    (
                        item,
                    ): item is IdeaCard & {
                        latitude: number;
                        longitude: number;
                    } => item.latitude !== null && item.longitude !== null,
                )
                .map((item) => ({
                    title: item.title,
                    typeLabel: t(`explore.rows.${row.key}`),
                    image: item.image,
                    slug: item.slug,
                    latitude: item.latitude,
                    longitude: item.longitude,
                })),
        );
});
</script>

<template>
    <section id="explore-section">
        <div class="section-head">
            <div class="eyebrow">{{ t('explore.eyebrow') }}</div>
            <h2>{{ t('explore.title') }}</h2>
            <p>{{ t('explore.subtitle') }}</p>
        </div>
        <div class="filter-bar" ref="filterBar">
            <div class="filter-row">
                <div class="filter-pair">
                    <select v-model="filterRegion">
                        <option value="">
                            {{ t('explore.filters.allRegions') }}
                        </option>
                        <option
                            v-for="region in regionOptions"
                            :key="region"
                            :value="region"
                        >
                            {{ region }}
                        </option>
                    </select>
                    <select v-model="filterCity">
                        <option value="">
                            {{ t('explore.filters.allCities') }}
                        </option>
                        <option
                            v-for="city in cityOptions"
                            :key="city"
                            :value="city"
                        >
                            {{ city }}
                        </option>
                    </select>
                </div>
                <select v-model="filterCategory">
                    <option value="">
                        {{ t('explore.filters.allCategories') }}
                    </option>
                    <option value="accommodation">
                        {{ t('explore.filters.accommodation') }}
                    </option>
                    <option value="activity">
                        {{ t('explore.filters.activities') }}
                    </option>
                    <option value="restaurant">
                        {{ t('explore.filters.restaurants') }}
                    </option>
                    <option value="vehicle">
                        {{ t('explore.filters.vehicles') }}
                    </option>
                    <option value="region">
                        {{ t('explore.filters.regions') }}
                    </option>
                </select>
                <input
                    ref="dateInput"
                    type="text"
                    :placeholder="t('explore.filters.selectDate')"
                    readonly
                    style="width: 170px; cursor: pointer"
                />
                <input
                    v-model="filterKeyword"
                    type="text"
                    :placeholder="t('explore.filters.keyword')"
                />
                <button class="search-btn" @click="performSearch(1)">
                    {{ t('explore.filters.search') }}
                </button>
                <button
                    class="filter-toggle"
                    @click="filterMoreOpen = !filterMoreOpen"
                >
                    {{
                        filterMoreOpen
                            ? t('explore.filters.hideFilters')
                            : t('explore.filters.moreFilters')
                    }}
                </button>
                <button
                    v-if="hasActiveFilters"
                    class="filter-clear"
                    @click="clearFilters"
                >
                    {{ t('explore.filters.clearFilters') }}
                </button>
                <button
                    v-if="!searchMode && mapMarkers.length > 0"
                    type="button"
                    class="map-toggle-btn"
                    :aria-pressed="mapOpen"
                    @click="mapOpen = !mapOpen"
                >
                    {{
                        mapOpen
                            ? t('explore.results.hideMap')
                            : t('explore.results.showMap')
                    }}
                </button>
                <span class="filter-note">{{ t('explore.filters.note') }}</span>
            </div>
            <div class="filter-more" :class="{ open: filterMoreOpen }">
                <select v-model="filterBudget">
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
                <select v-model="filterMinRating">
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
            </div>
        </div>
        <!-- Search results mode -->
        <template v-if="searchMode">
            <div class="search-results-header">
                <span class="search-results-count">
                    {{ t('explore.results.count', searchMeta.total) }}
                </span>
                <div class="search-results-controls">
                    <label class="sort-label">
                        {{ t('explore.results.sortBy') }}
                        <select
                            v-model="sortBy"
                            class="sort-select"
                            @change="performSearch(1)"
                        >
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
                        </select>
                    </label>
                    <button class="filter-clear" @click="clearFilters">
                        {{ t('explore.results.backToInspiration') }}
                    </button>
                </div>
            </div>

            <p
                v-if="searchLoading && !searchResults.length"
                class="filter-empty"
            >
                {{ t('explore.results.searching') }}
            </p>
            <p
                v-else-if="!searchLoading && !searchResults.length"
                class="filter-empty"
            >
                {{ t('explore.results.noResults') }}
            </p>

            <div v-else class="result-cards">
                <Link
                    v-for="item in searchResults"
                    :key="item.id"
                    :href="listingUrl(item.slug)"
                    class="result-card"
                    @click="saveScrollPosition"
                >
                    <div class="result-card-thumb">
                        <span class="idea-tag">{{
                            t(`explore.rows.${item.type}`)
                        }}</span>
                        <button
                            type="button"
                            class="idea-shortlist-toggle"
                            :class="{ active: shortlist.has(item.id) }"
                            :aria-pressed="shortlist.has(item.id)"
                            :title="t('explore.shortlist.toggle')"
                            @click.stop.prevent="toggleSearchShortlist(item)"
                        >
                            {{ shortlist.has(item.id) ? '✓' : '+' }}
                        </button>
                        <img
                            v-bind="
                                thumbAttrs(
                                    item.image ??
                                        `/images/explore/${item.type}-1.jpg`,
                                    400,
                                )
                            "
                            :alt="item.name"
                            loading="lazy"
                            decoding="async"
                            width="400"
                            height="300"
                            class="result-card-img"
                            @error="onImageError($event, item.type)"
                        />
                    </div>
                    <div class="result-card-body">
                        <div
                            v-if="item.rating !== null"
                            class="result-card-rating"
                        >
                            ★ {{ item.rating.toFixed(1) }}
                            <span
                                v-if="item.rating_count"
                                class="result-card-rating-count"
                                >({{ item.rating_count }})</span
                            >
                        </div>
                        <h4 class="result-card-name">{{ item.name }}</h4>
                        <p
                            v-if="item.city || item.region"
                            class="result-card-region"
                        >
                            {{ item.city ?? item.region }}
                        </p>
                        <p v-if="item.description" class="result-card-desc">
                            {{ truncate(item.description, 110) }}
                        </p>
                        <div class="result-card-footer">
                            <!-- Qualified where the listing records it: this
                                 grid is read as a price comparison, which two
                                 rates quoted per different things break. -->
                            <span
                                v-if="priceLabel(item)"
                                class="result-card-price"
                            >
                                {{ t('listing.from') }}
                                {{ priceLabel(item) }}
                            </span>
                            <span class="result-card-cta">{{
                                t('explore.results.viewDetails')
                            }}</span>
                        </div>
                    </div>
                </Link>
            </div>

            <button
                v-if="searchMeta.current_page < searchMeta.last_page"
                class="load-more-btn"
                :disabled="searchLoading"
                @click="performSearch(searchMeta.current_page + 1, true)"
            >
                {{
                    searchLoading
                        ? t('explore.results.searching')
                        : t('explore.results.loadMore')
                }}
            </button>
        </template>

        <!-- Inspiration / magazine mode -->
        <template v-else>
            <ExploreMap
                v-if="mapOpen && mapMarkers.length > 0"
                :markers="mapMarkers"
                :back-query="listingBackQuery"
                map-id="explore-map"
                @navigate="saveScrollPosition"
            />

            <Link
                v-if="featuredPick && !hasActiveFilters"
                :href="show({ listing: featuredPick.slug }).url"
                class="featured-pick"
                @click="saveScrollPosition"
            >
                <div class="featured-pick-media">
                    <img
                        v-bind="thumbAttrs(featuredPickImage!, 800)"
                        :alt="featuredPick.name"
                        class="featured-pick-img"
                        decoding="async"
                        @error="onImageError($event, featuredPick.type)"
                    />
                </div>
                <div class="featured-pick-body">
                    <span class="eyebrow">{{
                        t('explore.featured.eyebrow')
                    }}</span>
                    <h3>{{ featuredPick.name }}</h3>
                    <p
                        v-if="featuredPick.rating !== null"
                        class="featured-pick-rating"
                    >
                        ★ {{ featuredPick.rating.toFixed(1) }}
                        <span v-if="featuredPick.city || featuredPick.region">
                            ·
                            {{ featuredPick.city ?? featuredPick.region }}</span
                        >
                    </p>
                    <p
                        v-else-if="featuredPick.city || featuredPick.region"
                        class="featured-pick-region"
                    >
                        {{ featuredPick.city ?? featuredPick.region }}
                    </p>
                    <p
                        v-if="featuredPick.description"
                        class="featured-pick-desc"
                    >
                        {{ truncate(featuredPick.description, 220) }}
                    </p>
                    <span class="featured-pick-cta">{{
                        t('explore.results.viewDetails')
                    }}</span>
                </div>
            </Link>

            <p v-if="!hasResults" class="filter-empty">
                {{ t('explore.filters.empty') }}
            </p>
            <div>
                <div
                    v-for="row in visibleRows"
                    :key="row.key"
                    class="inspire-row"
                >
                    <div class="inspire-row-head">
                        <h3>{{ t(`explore.rows.${row.key}`) }}</h3>
                        <button
                            v-if="row.key !== 'region'"
                            type="button"
                            class="inspire-row-view-all"
                            @click="viewAllInRow(row)"
                        >
                            {{ t('explore.viewAll') }}
                        </button>
                    </div>
                    <div class="idea-cards">
                        <component
                            :is="
                                item.slug
                                    ? Link
                                    : row.key === 'region'
                                      ? 'button'
                                      : 'div'
                            "
                            v-for="item in row.items.slice(0, 8)"
                            :key="item.title"
                            :href="
                                item.slug ? listingUrl(item.slug) : undefined
                            "
                            class="idea-card"
                            @click="onIdeaCardClick(row, item)"
                        >
                            <div
                                class="idea-thumb"
                                :style="{ background: `var(--${row.bg})` }"
                            >
                                <span class="idea-tag">{{
                                    t(`explore.rows.${row.key}`)
                                }}</span>
                                <button
                                    v-if="item.id !== null"
                                    type="button"
                                    class="idea-shortlist-toggle"
                                    :class="{ active: shortlist.has(item.id) }"
                                    :aria-pressed="shortlist.has(item.id)"
                                    :title="t('explore.shortlist.toggle')"
                                    @click.stop.prevent="toggleShortlist(item)"
                                >
                                    {{ shortlist.has(item.id) ? '✓' : '+' }}
                                </button>
                                <img
                                    v-bind="thumbAttrs(item.image, 400)"
                                    :alt="item.title"
                                    class="idea-thumb-img"
                                    width="400"
                                    height="300"
                                    loading="lazy"
                                    decoding="async"
                                    @error="onImageError($event, row.key)"
                                />
                            </div>
                            <div class="idea-body">
                                <h4>{{ item.title }}</h4>
                                <p
                                    v-if="item.rating !== null"
                                    class="idea-rating"
                                >
                                    ★ {{ item.rating.toFixed(1) }}
                                </p>
                                <p>{{ item.description }}</p>
                            </div>
                        </component>
                    </div>
                </div>
            </div>
        </template>

        <ShortlistBar
            :items="shortlist"
            @remove="removeFromShortlist"
            @clear="clearShortlist"
        />
    </section>
</template>
