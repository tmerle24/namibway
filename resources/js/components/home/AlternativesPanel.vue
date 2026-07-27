<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { ItineraryListingRef } from '@/lib/kaia-types';
import { show } from '@/routes/listings';

defineProps<{
    loading: boolean;
    alternatives: ItineraryListingRef[];
}>();

const emit = defineEmits<{
    (e: 'select', alt: ItineraryListingRef): void;
}>();

const { t } = useI18n();

function detailUrl(slug: string): string {
    return show({ listing: slug }).url;
}
</script>

<template>
    <div class="alternatives-panel">
        <div class="alternatives-title">{{ t('itinerary.alternatives') }}</div>
        <div v-if="loading" class="alternatives-loading">…</div>
        <template v-else-if="alternatives.length">
            <div
                v-for="alt in alternatives"
                :key="String(alt.id)"
                class="alternative-item"
            >
                <span class="alt-name">{{ alt.name }}</span>
                <span v-if="alt.price_from" class="alt-price"
                    >{{ alt.price_currency }}
                    {{ Number(alt.price_from).toLocaleString() }}</span
                >
                <a
                    v-if="alt.slug"
                    :href="detailUrl(alt.slug)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="alt-detail-link"
                    :title="t('itinerary.viewDetail')"
                    >↗</a
                >
                <button
                    type="button"
                    class="alt-use-btn"
                    @click="emit('select', alt)"
                >
                    {{ t('itinerary.useThis') }}
                </button>
            </div>
        </template>
        <div v-else class="alternatives-empty">
            {{ t('itinerary.noAlternatives') }}
        </div>
    </div>
</template>
