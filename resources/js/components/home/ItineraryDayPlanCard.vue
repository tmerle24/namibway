<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { DayEntry } from '@/lib/kaia-types';
import ItineraryEntryRow from './ItineraryEntryRow.vue';
import KebabMenu from './KebabMenu.vue';

defineProps<{
    // Pre-merged and pre-sorted by the parent's dayEntries().
    entries: DayEntry[];
    // Opened through a read-only share link: the day is still shown in full,
    // it just offers nothing that would try to write.
    readonly?: boolean;
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
    <!-- The day's own date and its arrival/departure label live on the
         timeline rail beside this card (see ItinerarySection.vue), so the card
         itself carries nothing but what happens that day. -->
    <div class="day-card day-card--continuation day-card--day-plan">
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
            <KebabMenu
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

        <!-- Every day now has a row of its own, empty ones included. In edit
             mode the add buttons above already say what an empty day is for;
             a shared or printed plan has no buttons, so it says so in words
             rather than showing a blank box. -->
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
</template>
