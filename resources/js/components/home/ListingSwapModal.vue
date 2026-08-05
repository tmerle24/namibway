<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatPrice } from '@/lib/currency';
import type { ListingSearchMeta, ListingSearchResult } from '@/lib/kaia-client';
import { searchListings } from '@/lib/kaia-client';
import type { ItineraryListingRef } from '@/lib/kaia-types';
import { show } from '@/routes/listings';

const props = defineProps<{
    type: 'accommodation' | 'activity' | 'restaurant';
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
const filtersOpen = ref(false);

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
            city: city.value || undefined,
            keyword: keyword.value || undefined,
            budget: budget.value || undefined,
            minRating: minRating.value || undefined,
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

watch([city, budget, minRating], () => runSearch(1, false));

function detailUrl(slug: string): string {
    return show({ listing: slug }).url;
}

function select(result: ListingSearchResult) {
    emit('select', {
        id: result.id,
        slug: result.slug,
        name: result.name,
        type: result.type,
        price_from: result.price_from,
        price_currency: result.price_currency,
        image: result.image,
        gallery: result.gallery,
        city: result.city,
        region: result.region,
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
                            :src="result.image"
                            :alt="result.name"
                            class="swap-modal-item-thumb"
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
                            <span
                                v-if="result.rating !== null"
                                class="swap-modal-item-rating"
                                >★ {{ result.rating.toFixed(1) }}</span
                            >
                        </span>
                        <span
                            v-if="formatPrice(result.price_from)"
                            class="swap-modal-item-price"
                            >{{ formatPrice(result.price_from) }}</span
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

.swap-modal-item-rating {
    font-size: 12px;
    color: #8a7f68;
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
