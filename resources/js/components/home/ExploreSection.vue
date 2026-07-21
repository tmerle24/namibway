<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import 'flatpickr/dist/flatpickr.min.css';
import flatpickr from 'flatpickr';
import type { Instance as FlatpickrInstance } from 'flatpickr/dist/types/instance';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { show } from '@/routes/listings';

interface Listing {
    id: number;
    type: 'accommodation' | 'activity' | 'restaurant';
    name: string;
    slug: string;
    description: string | null;
    image: string | null;
    region: string | null;
    price_from: string | null;
    price_currency: string;
}

interface Region {
    name: string;
    slug: string;
    blurb: string | null;
    image: string | null;
    listing_region: string;
}

const props = defineProps<{
    listings: Listing[];
    regions: Region[];
}>();

const { t } = useI18n();

type Budget = 'budget' | 'mid-range' | 'premium' | null;
type RowKey = 'accommodation' | 'activity' | 'restaurant' | 'region';

interface IdeaCard {
    title: string;
    description: string;
    region: string | null;
    budget: Budget;
    image: string;
    slug: string | null;
}

interface IdeaRow {
    key: RowKey;
    bg: string;
    items: IdeaCard[];
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

function truncate(text: string | null, length = 120): string {
    if (!text) {
return '';
}

    return text.length > length ? text.slice(0, length).trim() + '…' : text;
}

const ROW_BG: Record<RowKey, string> = {
    accommodation: 'rust-light',
    activity: 'sage-light',
    restaurant: 'sand',
    region: 'sand-dark',
};

const ideaRows = computed<IdeaRow[]>(() => {
    const byType: Record<Listing['type'], IdeaCard[]> = { accommodation: [], activity: [], restaurant: [] };

    for (const listing of props.listings) {
        const images = CATEGORY_IMAGES[listing.type];
        byType[listing.type].push({
            title: listing.name,
            description: truncate(listing.description),
            region: listing.region,
            budget: budgetBucket(listing.price_from),
            image: listing.image ?? images[byType[listing.type].length % images.length],
            slug: listing.slug,
        });
    }

    const rows: IdeaRow[] = (Object.keys(byType) as Listing['type'][])
        .filter((type) => byType[type].length > 0)
        .map((type) => ({ key: type, bg: ROW_BG[type], items: byType[type] }));

    rows.push({
        key: 'region',
        bg: ROW_BG.region,
        items: props.regions.map((region) => ({
            title: region.name,
            description: region.blurb ?? '',
            region: region.listing_region,
            budget: null,
            image: region.image ?? '/images/explore/region-khomas.jpg',
            slug: null,
        })),
    });

    return rows;
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

const filterCategory = ref('');
const filterRegion = ref('');
const filterBudget = ref('');
const filterKeyword = ref('');
const filterMoreOpen = ref(false);
const dateInput = ref<HTMLInputElement | null>(null);
const filterBar = ref<HTMLDivElement | null>(null);
let fp: FlatpickrInstance | null = null;

function selectRegion(region: string) {
    filterCategory.value = '';
    filterRegion.value = region;
    filterMoreOpen.value = true;
    filterBar.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

onMounted(() => {
    if (dateInput.value) {
        fp = flatpickr(dateInput.value, {
            mode: 'range',
            dateFormat: 'd M',
            minDate: 'today',
        });
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

    if (filterBudget.value && item.budget !== null && item.budget !== filterBudget.value) {
return false;
}

    if (filterBudget.value && item.budget === null && row.key !== 'region') {
return false;
}

    const keyword = filterKeyword.value.trim().toLowerCase();

    if (keyword && !(item.title + ' ' + item.description).toLowerCase().includes(keyword)) {
return false;
}

    return true;
}

const visibleRows = computed(() =>
    ideaRows.value
        .map((row) => ({ ...row, items: row.items.filter((item) => matches(row, item)) }))
        .filter((row) => row.items.length > 0),
);

const hasResults = computed(() => visibleRows.value.length > 0);
</script>

<template>
    <section>
        <div class="section-head">
            <div class="eyebrow">{{ t('explore.eyebrow') }}</div>
            <h2>{{ t('explore.title') }}</h2>
            <p>{{ t('explore.subtitle') }}</p>
        </div>
        <div class="filter-bar" ref="filterBar">
            <div class="filter-row">
                <select v-model="filterCategory">
                    <option value="">{{ t('explore.filters.allCategories') }}</option>
                    <option value="accommodation">{{ t('explore.filters.accommodation') }}</option>
                    <option value="activity">{{ t('explore.filters.activities') }}</option>
                    <option value="restaurant">{{ t('explore.filters.restaurants') }}</option>
                    <option value="region">{{ t('explore.filters.regions') }}</option>
                </select>
                <label>{{ t('explore.filters.date') }} <input ref="dateInput" type="text" :placeholder="t('explore.filters.selectDate')" readonly style="width: 170px; cursor: pointer" /></label>
                <input v-model="filterKeyword" type="text" :placeholder="t('explore.filters.keyword')" />
                <button class="search-btn">{{ t('explore.filters.search') }}</button>
                <button class="filter-toggle" @click="filterMoreOpen = !filterMoreOpen">
                    {{ filterMoreOpen ? t('explore.filters.hideFilters') : t('explore.filters.moreFilters') }}
                </button>
                <span class="filter-note">{{ t('explore.filters.note') }}</span>
            </div>
            <div class="filter-more" :class="{ open: filterMoreOpen }">
                <select v-model="filterRegion">
                    <option value="">{{ t('explore.filters.allRegions') }}</option>
                    <option v-for="region in availableRegions" :key="region" :value="region">{{ region }}</option>
                </select>
                <select v-model="filterBudget">
                    <option value="">{{ t('explore.filters.anyBudget') }}</option>
                    <option value="budget">{{ t('explore.filters.budget') }}</option>
                    <option value="mid-range">{{ t('explore.filters.midRange') }}</option>
                    <option value="premium">{{ t('explore.filters.premium') }}</option>
                </select>
            </div>
        </div>
        <p v-if="!hasResults" class="filter-empty">{{ t('explore.filters.empty') }}</p>
        <div>
            <div v-for="row in visibleRows" :key="row.key" class="inspire-row">
                <div class="inspire-row-head">
                    <h3>{{ t(`explore.rows.${row.key}`) }}</h3>
                    <span>{{ t('explore.examples', { count: row.items.length }) }}</span>
                </div>
                <div class="idea-cards">
                    <component
                        :is="item.slug ? Link : row.key === 'region' ? 'button' : 'div'"
                        v-for="item in row.items"
                        :key="item.title"
                        :href="item.slug ? show({ listing: item.slug }).url : undefined"
                        class="idea-card"
                        @click="row.key === 'region' && item.region ? selectRegion(item.region) : undefined"
                    >
                        <div class="idea-thumb" :style="{ background: `var(--${row.bg})` }">
                            <span class="idea-tag">{{ t(`explore.rows.${row.key}`) }}</span>
                            <img :src="item.image" :alt="item.title" class="idea-thumb-img" loading="lazy" />
                        </div>
                        <div class="idea-body">
                            <h4>{{ item.title }}</h4>
                            <p>{{ item.description }}</p>
                        </div>
                    </component>
                </div>
            </div>
        </div>
    </section>
</template>
