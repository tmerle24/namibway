<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { DayEntry } from '@/lib/kaia-types';
import ItineraryLineItem from './ItineraryLineItem.vue';
import KebabMenu from './KebabMenu.vue';

defineProps<{
    // Already formatted for display by the parent (dayDateLabel) — this card
    // only ever shows one day, so it needs no date logic of its own.
    dateLabel: string;
    // Pre-merged and pre-sorted by the parent's dayEntries().
    entries: DayEntry[];
    // A stage's first day renders this card directly under its accommodation
    // card, which already carries the day's "remove day" menu — a second one
    // here would act on the same day twice over.
    showDayMenu?: boolean;
}>();

const emit = defineEmits<{
    (e: 'add', type: 'activity' | 'restaurant'): void;
    (e: 'swap', entry: DayEntry): void;
    (e: 'remove', entry: DayEntry): void;
    (e: 'update-time', entry: DayEntry, value: string | null): void;
    (e: 'remove-day'): void;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="day-card day-card--continuation day-card--day-plan">
        <div class="day-plan-head">
            <span class="day-plan-date">{{ dateLabel }}</span>
            <KebabMenu
                v-if="showDayMenu"
                :items="[
                    {
                        key: 'delete',
                        label: t('itinerary.removeDay'),
                        danger: true,
                    },
                ]"
                :label="t('itinerary.dayOptions')"
                @select="emit('remove-day')"
            />
        </div>

        <div class="day-plan-add-row">
            <button
                type="button"
                class="add-item-btn"
                @click="emit('add', 'activity')"
            >
                + {{ t('itinerary.addActivity') }}
            </button>
            <button
                type="button"
                class="add-item-btn"
                @click="emit('add', 'restaurant')"
            >
                + {{ t('itinerary.addRestaurant') }}
            </button>
        </div>

        <ItineraryLineItem
            v-for="entry in entries"
            :key="`${entry.type}-${entry.itemIndex}-${entry.item.id ?? entry.item.name}`"
            hide-label
            :keypath="
                entry.type === 'activity'
                    ? 'itinerary.activity'
                    : 'itinerary.dinner'
            "
            :item-ref="entry.item"
            :icon="entry.type === 'activity' ? '📷' : '🍴'"
            :type-label="
                t(
                    entry.type === 'activity'
                        ? 'itinerary.activityLabel'
                        : 'itinerary.dinnerLabel',
                )
            "
            :time="entry.item.time"
            @update:time="(value) => emit('update-time', entry, value)"
            @remove="emit('remove', entry)"
            @swap="emit('swap', entry)"
        />
    </div>
</template>
