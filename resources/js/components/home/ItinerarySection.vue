<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { ItineraryListingRef, ItineraryPlan, ItineraryVariant } from '@/lib/kaia-types';
import { show } from '@/routes/listings';

defineProps<{
    plan: ItineraryPlan;
}>();

const emit = defineEmits<{
    (e: 'book', variant: ItineraryVariant): void;
}>();

const { t } = useI18n();

function refUrl(ref: ItineraryListingRef): string {
    return show({ listing: ref.slug ?? '' }).url;
}
</script>

<template>
    <section id="itinerary-section">
        <div class="section-head">
            <div class="eyebrow">{{ t('itinerary.eyebrow') }}</div>
            <h2>{{ t('itinerary.title') }}</h2>
            <p>{{ t('itinerary.subtitle') }}</p>
        </div>
        <div class="variants">
            <div v-for="variant in plan.variants" :key="variant.name" class="variant-card">
                <h3>{{ variant.name }}</h3>
                <div class="variant-price">{{ t('itinerary.estimated', { amount: variant.estimated_total_usd.toLocaleString() }) }}</div>
                <i18n-t v-if="variant.vehicle" keypath="itinerary.vehicle" tag="div" class="variant-vehicle">
                    <template #value>
                        <a v-if="variant.vehicle.slug" :href="refUrl(variant.vehicle)" target="_blank" rel="noopener noreferrer">{{ variant.vehicle.name }}</a>
                        <template v-else>{{ variant.vehicle.name }}</template>
                    </template>
                </i18n-t>
                <div v-for="day in variant.days" :key="day.day" class="day-row">
                    <div class="day-num">{{ day.day }}</div>
                    <div class="day-detail">
                        <div><b>{{ day.location }}</b></div>
                        <i18n-t keypath="itinerary.stay" tag="div">
                            <template #value>
                                <a v-if="day.accommodation?.slug" :href="refUrl(day.accommodation)" target="_blank" rel="noopener noreferrer">{{ day.accommodation.name }}</a>
                                <template v-else>{{ day.accommodation?.name ?? '—' }}</template>
                            </template>
                        </i18n-t>
                        <i18n-t keypath="itinerary.activity" tag="div">
                            <template #value>
                                <a v-if="day.activity?.slug" :href="refUrl(day.activity)" target="_blank" rel="noopener noreferrer">{{ day.activity.name }}</a>
                                <template v-else>{{ day.activity?.name ?? '—' }}</template>
                            </template>
                        </i18n-t>
                        <i18n-t keypath="itinerary.dinner" tag="div">
                            <template #value>
                                <a v-if="day.restaurant?.slug" :href="refUrl(day.restaurant)" target="_blank" rel="noopener noreferrer">{{ day.restaurant.name }}</a>
                                <template v-else>{{ day.restaurant?.name ?? '—' }}</template>
                            </template>
                        </i18n-t>
                    </div>
                </div>
                <button class="cta" @click="emit('book', variant)">{{ t('itinerary.bookCta') }}</button>
            </div>
        </div>
    </section>
</template>
