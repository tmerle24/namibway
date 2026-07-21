<script setup lang="ts">
import 'flatpickr/dist/flatpickr.min.css';
import flatpickr from 'flatpickr';
import type { Instance as FlatpickrInstance } from 'flatpickr/dist/types/instance';
import { computed, onMounted, onUnmounted, ref } from 'vue';

interface Listing {
    id: number;
    type: 'accommodation' | 'activity' | 'restaurant';
    name: string;
    slug: string;
    description: string | null;
    region: string | null;
    price_from: string | null;
    price_currency: string;
}

const props = defineProps<{
    listings: Listing[];
}>();

type Budget = 'budget' | 'mid-range' | 'premium' | null;

interface IdeaCard {
    title: string;
    description: string;
    region: string | null;
    budget: Budget;
    image: string;
}

interface IdeaRow {
    key: string;
    title: string;
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

const REGION_INFO: { name: string; blurb: string; image: string }[] = [
    { name: 'Khomas', blurb: 'Windhoek and the central highlands — most itineraries start here.', image: '/images/explore/region-khomas.jpg' },
    { name: 'Erongo', blurb: 'Coastal dunes, Swakopmund, and the Skeleton Coast approach.', image: '/images/explore/region-erongo.jpg' },
    { name: 'Hardap', blurb: 'Home to the Sossusvlei dune fields and the Namib.', image: '/images/explore/region-hardap.jpg' },
    { name: 'Kunene', blurb: "Damaraland's rugged desert and Etosha's western reaches.", image: '/images/explore/region-kunene.jpg' },
    { name: 'Otjozondjupa', blurb: 'Bushveld and the road east toward the Kalahari.', image: '/images/explore/region-otjozondjupa.jpg' },
    { name: 'Karas', blurb: 'The far south — Fish River Canyon and the Orange River.', image: '/images/explore/region-karas.jpg' },
];

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

const TYPE_ROW_META: Record<Listing['type'], Pick<IdeaRow, 'title' | 'bg'>> = {
    accommodation: { title: 'Places to stay', bg: 'rust-light' },
    activity: { title: 'Things to do', bg: 'sage-light' },
    restaurant: { title: 'Where to eat', bg: 'sand' },
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
            image: images[byType[listing.type].length % images.length],
        });
    }

    const rows: IdeaRow[] = (Object.keys(TYPE_ROW_META) as Listing['type'][])
        .filter((type) => byType[type].length > 0)
        .map((type) => ({ key: type, items: byType[type], ...TYPE_ROW_META[type] }));

    rows.push({
        key: 'region',
        title: 'Regions to explore',
        bg: 'sand-dark',
        items: REGION_INFO.map((r) => ({ title: r.name, description: r.blurb, region: r.name, budget: null, image: r.image })),
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
let fp: FlatpickrInstance | null = null;

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
    if (filterCategory.value && row.title !== filterCategory.value) {
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
            <div class="eyebrow">Explore</div>
            <h2>Get inspired, browse anytime</h2>
            <p>No interview needed — the same magazine-style catalog is browsable directly, for travelers who'd rather look than chat.</p>
        </div>
        <div class="filter-bar">
            <div class="filter-row">
                <select v-model="filterCategory">
                    <option value="">All categories</option>
                    <option value="Places to stay">Accommodation</option>
                    <option value="Things to do">Activities</option>
                    <option value="Where to eat">Restaurants</option>
                    <option value="Regions to explore">Regions</option>
                </select>
                <label>Date <input ref="dateInput" type="text" placeholder="Select date(s)" readonly style="width: 170px; cursor: pointer" /></label>
                <input v-model="filterKeyword" type="text" placeholder="Search by keyword…" />
                <button class="search-btn">Search</button>
                <button class="filter-toggle" @click="filterMoreOpen = !filterMoreOpen">
                    {{ filterMoreOpen ? 'Hide filters ▲' : 'More filters ▾' }}
                </button>
                <span class="filter-note">Dates are captured for when live partner availability is connected — this catalog isn't date-checked yet.</span>
            </div>
            <div class="filter-more" :class="{ open: filterMoreOpen }">
                <select v-model="filterRegion">
                    <option value="">All regions</option>
                    <option v-for="region in availableRegions" :key="region" :value="region">{{ region }}</option>
                </select>
                <select v-model="filterBudget">
                    <option value="">Any budget</option>
                    <option value="budget">Budget</option>
                    <option value="mid-range">Mid-range</option>
                    <option value="premium">Premium</option>
                </select>
            </div>
        </div>
        <p v-if="!hasResults" class="filter-empty">No matches — try clearing a filter.</p>
        <div>
            <div v-for="row in visibleRows" :key="row.key" class="inspire-row">
                <div class="inspire-row-head">
                    <h3>{{ row.title }}</h3>
                    <span>{{ row.items.length }} examples</span>
                </div>
                <div class="idea-cards">
                    <div v-for="item in row.items" :key="item.title" class="idea-card">
                        <div class="idea-thumb" :style="{ background: `var(--${row.bg})` }">
                            <span class="idea-tag">{{ row.title }}</span>
                            <img :src="item.image" :alt="item.title" class="idea-thumb-img" loading="lazy" />
                        </div>
                        <div class="idea-body">
                            <h4>{{ item.title }}</h4>
                            <p>{{ item.description }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
