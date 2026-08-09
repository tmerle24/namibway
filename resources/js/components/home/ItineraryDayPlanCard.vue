<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import type { DayEntry } from '@/lib/kaia-types';
import ItineraryEntryRow from './ItineraryEntryRow.vue';
import KebabMenu from './KebabMenu.vue';

defineProps<{
    // Pre-merged and pre-sorted by the parent's dayEntries().
    entries: DayEntry[];
    // "9 Jan" — the day's own date belongs to the day's content, not to the
    // timeline rail beside it (the rail keeps only the small day marker).
    dateLabel: string;
    // "Ankunft"/"Abreise" beside the date, null for an ordinary day.
    dayLabel?: string | null;
    // Collapsing folds the day's entries away but keeps this header line, so
    // a folded day still says which day it is.
    collapsed?: boolean;
    // Opened through a read-only share link: the day is still shown in full,
    // it just offers nothing that would try to write.
    readonly?: boolean;
    // The departure-day row's card has no day of its own to remove — it is
    // derived from the stage's last night — so it hides the day menu.
    hideDayMenu?: boolean;
}>();

const emit = defineEmits<{
    (e: 'add', type: 'activity' | 'restaurant'): void;
    (e: 'swap', entry: DayEntry): void;
    (e: 'remove', entry: DayEntry): void;
    (e: 'update-time', entry: DayEntry, value: string | null): void;
    (e: 'remove-day'): void;
    (e: 'toggle-collapse'): void;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="day-card day-card--continuation day-card--day-plan">
        <!-- The date heads the card so it reads as part of the day's content;
             the whole line doubles as the fold toggle. The day menu sits
             beside it rather than inside the toggle (no nested buttons). -->
        <div class="day-plan-header">
            <button
                type="button"
                class="day-plan-header-toggle"
                :aria-expanded="!collapsed"
                :aria-label="
                    collapsed
                        ? t('itinerary.expandDay')
                        : t('itinerary.collapseDay')
                "
                @click="emit('toggle-collapse')"
            >
                <span v-if="dateLabel" class="day-plan-date">{{
                    dateLabel
                }}</span>
                <span v-if="dayLabel" class="day-plan-day-label">{{
                    dayLabel
                }}</span>
                <ChevronDown
                    class="day-plan-chevron"
                    :class="{ 'day-plan-chevron--collapsed': collapsed }"
                    :size="14"
                />
            </button>
            <KebabMenu
                v-if="!readonly && !hideDayMenu"
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

        <div v-show="!collapsed" class="day-plan-body">
            <div v-if="!readonly" class="day-plan-add-row">
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

            <!-- Every day has a row of its own, empty ones included. In edit
                 mode the add buttons above already say what an empty day is
                 for; a shared or printed plan has no buttons, so it says so in
                 words rather than showing a blank box. -->
            <p v-if="readonly && !entries.length" class="day-plan-empty">
                {{ t('itinerary.dayEmpty') }}
            </p>

            <ItineraryEntryRow
                v-for="entry in entries"
                :key="`${entry.type}-${entry.itemIndex}-${entry.item.id ?? entry.item.name}`"
                :type="entry.type"
                :item="entry.item"
                :readonly="readonly"
                :time="entry.item.time"
                @update:time="(value) => emit('update-time', entry, value)"
                @remove="emit('remove', entry)"
                @swap="emit('swap', entry)"
            />
        </div>
    </div>
</template>
