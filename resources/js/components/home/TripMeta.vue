<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { TripParams } from '@/lib/kaia-types';

const props = defineProps<{
    tripParams?: TripParams | null;
}>();

const { t } = useI18n();

const travelersLabel = computed(() => {
    const p = props.tripParams;

    if (!p || !p.adults) {
        return null;
    }

    const parts = [t('itinerary.meta.adults', p.adults)];

    if (p.children_under_13) {
        parts.push(t('itinerary.meta.children', p.children_under_13));
    }

    return parts.join(', ');
});

const durationLabel = computed(() => {
    const nights = props.tripParams?.nights;

    return nights ? t('itinerary.meta.nights', nights) : null;
});

interface MetaItem {
    icon: string;
    label: string;
    value: string;
}

const items = computed<MetaItem[]>(() => {
    const p = props.tripParams;

    if (!p) {
        return [];
    }

    const list: (MetaItem | null)[] = [
        p.travel_period
            ? {
                  icon: '📅',
                  label: t('itinerary.meta.travelPeriod'),
                  value: p.travel_period,
              }
            : null,
        durationLabel.value
            ? {
                  icon: '🌙',
                  label: t('itinerary.meta.duration'),
                  value: durationLabel.value,
              }
            : null,
        travelersLabel.value
            ? {
                  icon: '👥',
                  label: t('itinerary.meta.travelers'),
                  value: travelersLabel.value,
              }
            : null,
        p.interests
            ? {
                  icon: '✨',
                  label: t('itinerary.meta.preferences'),
                  value: p.interests,
              }
            : null,
        p.budget_tier
            ? {
                  icon: '💰',
                  label: t('itinerary.meta.budget'),
                  value: t(`itinerary.meta.budgetTiers.${p.budget_tier}`),
              }
            : null,
    ];

    return list.filter((item): item is MetaItem => item !== null);
});
</script>

<template>
    <div v-if="items.length" class="trip-meta-row">
        <div v-for="item in items" :key="item.label" class="trip-meta-chip">
            <span class="trip-meta-icon" aria-hidden="true">{{
                item.icon
            }}</span>
            <span class="trip-meta-text"
                ><strong>{{ item.label }}:</strong> {{ item.value }}</span
            >
        </div>
    </div>
</template>
