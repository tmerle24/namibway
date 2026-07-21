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
}

interface IdeaRow {
    key: string;
    title: string;
    icon: 'lodge' | 'activity' | 'dining' | 'region';
    color: string;
    bg: string;
    items: IdeaCard[];
}

const ICONS: Record<IdeaRow['icon'], string> = {
    lodge: '<path d="M4 42 L24 14 L44 42 Z" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/><line x1="4" y1="42" x2="44" y2="42" stroke="currentColor" stroke-width="3"/><line x1="24" y1="26" x2="24" y2="42" stroke="currentColor" stroke-width="3"/>',
    activity: '<circle cx="24" cy="24" r="10" fill="none" stroke="currentColor" stroke-width="3"/><circle cx="24" cy="24" r="3" fill="currentColor"/><line x1="4" y1="24" x2="14" y2="24" stroke="currentColor" stroke-width="3"/><line x1="34" y1="24" x2="44" y2="24" stroke="currentColor" stroke-width="3"/>',
    dining: '<line x1="14" y1="6" x2="14" y2="42" stroke="currentColor" stroke-width="3"/><line x1="10" y1="6" x2="10" y2="18" stroke="currentColor" stroke-width="3"/><line x1="14" y1="6" x2="14" y2="18" stroke="currentColor" stroke-width="3"/><line x1="18" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="3"/><path d="M34 6 C28 6 28 18 34 20 L34 42" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>',
    region: '<path d="M4 36 L16 16 L24 28 L32 12 L44 36 Z" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>',
};

const REGION_INFO: { name: string; blurb: string }[] = [
    { name: 'Khomas', blurb: 'Windhoek and the central highlands — most itineraries start here.' },
    { name: 'Erongo', blurb: 'Coastal dunes, Swakopmund, and the Skeleton Coast approach.' },
    { name: 'Hardap', blurb: 'Home to the Sossusvlei dune fields and the Namib.' },
    { name: 'Kunene', blurb: "Damaraland's rugged desert and Etosha's western reaches." },
    { name: 'Otjozondjupa', blurb: 'Bushveld and the road east toward the Kalahari.' },
    { name: 'Karas', blurb: 'The far south — Fish River Canyon and the Orange River.' },
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

const TYPE_ROW_META: Record<Listing['type'], Pick<IdeaRow, 'title' | 'icon' | 'color' | 'bg'>> = {
    accommodation: { title: 'Places to stay', icon: 'lodge', color: 'var(--rust)', bg: 'rust-light' },
    activity: { title: 'Things to do', icon: 'activity', color: 'var(--sage)', bg: 'sage-light' },
    restaurant: { title: 'Where to eat', icon: 'dining', color: 'var(--gold)', bg: 'sand' },
};

const ideaRows = computed<IdeaRow[]>(() => {
    const byType: Record<string, IdeaCard[]> = { accommodation: [], activity: [], restaurant: [] };

    for (const listing of props.listings) {
        byType[listing.type]?.push({
            title: listing.name,
            description: truncate(listing.description),
            region: listing.region,
            budget: budgetBucket(listing.price_from),
        });
    }

    const rows: IdeaRow[] = (Object.keys(TYPE_ROW_META) as Listing['type'][])
        .filter((type) => byType[type].length > 0)
        .map((type) => ({ key: type, items: byType[type], ...TYPE_ROW_META[type] }));

    rows.push({
        key: 'region',
        title: 'Regions to explore',
        icon: 'region',
        color: 'var(--night)',
        bg: 'sand-dark',
        items: REGION_INFO.map((r) => ({ title: r.name, description: r.blurb, region: r.name, budget: null })),
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
                            <svg viewBox="0 0 48 48" :style="{ color: row.color }" v-html="ICONS[row.icon]"></svg>
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
