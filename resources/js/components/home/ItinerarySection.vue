<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { ItineraryPlan, ItineraryVariant } from '@/lib/kaia-types';

defineProps<{
    plan: ItineraryPlan;
}>();

const emit = defineEmits<{
    (e: 'book', variant: ItineraryVariant): void;
}>();

const { t } = useI18n();
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
                <div v-if="variant.vehicle" class="variant-vehicle">{{ t('itinerary.vehicle', { value: variant.vehicle }) }}</div>
                <div v-for="day in variant.days" :key="day.day" class="day-row">
                    <div class="day-num">{{ day.day }}</div>
                    <div class="day-detail">
                        <div><b>{{ day.location }}</b></div>
                        <div>{{ t('itinerary.stay', { value: day.accommodation || '—' }) }}</div>
                        <div>{{ t('itinerary.activity', { value: day.activity || '—' }) }}</div>
                        <div>{{ t('itinerary.dinner', { value: day.restaurant || '—' }) }}</div>
                    </div>
                </div>
                <button class="cta" @click="emit('book', variant)">{{ t('itinerary.bookCta') }}</button>
            </div>
        </div>
    </section>
</template>
