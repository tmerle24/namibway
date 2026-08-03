<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { TripParams } from '@/lib/kaia-types';

const props = defineProps<{
    tripParams?: TripParams | null;
    routeStart?: string | null;
    routeEnd?: string | null;
    editable?: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit'): void;
}>();

const { t } = useI18n();

const travelersLabel = computed(() => {
    const p = props.tripParams;

    if (!p || !p.adults) {
        return null;
    }

    const parts = [t('itinerary.meta.adults', p.adults)];

    if (p.children_under_13) {
        const childrenLabel = t('itinerary.meta.children', p.children_under_13);
        parts.push(
            p.children_ages
                ? `${childrenLabel} (${p.children_ages})`
                : childrenLabel,
        );
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

const routeLabel = computed(() => {
    if (!props.routeStart || !props.routeEnd) {
        return null;
    }

    return props.routeStart.trim().toLowerCase() ===
        props.routeEnd.trim().toLowerCase()
        ? props.routeStart
        : `${props.routeStart} → ${props.routeEnd}`;
});

const items = computed<MetaItem[]>(() => {
    const p = props.tripParams;
    const list: (MetaItem | null)[] = [
        routeLabel.value
            ? {
                  icon: '🧭',
                  label: t('itinerary.route'),
                  value: routeLabel.value,
              }
            : null,
    ];

    if (!p) {
        return list.filter((item): item is MetaItem => item !== null);
    }

    list.push(
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
    );

    return list.filter((item): item is MetaItem => item !== null);
});
</script>

<template>
    <div
        v-if="items.length || editable"
        class="trip-meta-row"
        :class="{ 'trip-meta-row--editable': editable }"
        :role="editable ? 'button' : undefined"
        :tabindex="editable ? 0 : undefined"
        @click="editable && emit('edit')"
        @keydown.enter="editable && emit('edit')"
    >
        <div v-for="item in items" :key="item.label" class="trip-meta-chip">
            <span class="trip-meta-icon" aria-hidden="true">{{
                item.icon
            }}</span>
            <span class="trip-meta-text"
                ><strong>{{ item.label }}:</strong> {{ item.value }}</span
            >
        </div>
        <button
            v-if="editable"
            type="button"
            class="trip-meta-edit-btn"
            @click.stop="emit('edit')"
        >
            ✏️ {{ t('itinerary.meta.edit') }}
        </button>
    </div>
</template>
