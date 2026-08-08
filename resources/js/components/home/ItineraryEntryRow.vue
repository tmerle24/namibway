<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatPrice } from '@/lib/currency';
import { formatDuration } from '@/lib/duration';
import type { ItineraryListingRef } from '@/lib/kaia-types';
import KebabMenu from './KebabMenu.vue';
import ListingPreviewModal from './ListingPreviewModal.vue';

// One row of a day's merged plan: an activity or a restaurant, laid out as
// time · icon · (name / details) · menu. Split out of ItineraryLineItem
// because those rows carry a second line of their own and a time control the
// stay/vehicle line items never had.
const props = defineProps<{
    type: 'activity' | 'restaurant';
    item: ItineraryListingRef;
    time?: string | null;
    readonly?: boolean;
}>();

const emit = defineEmits<{
    (e: 'remove'): void;
    (e: 'swap'): void;
    (e: 'update:time', value: string | null): void;
}>();

const { t } = useI18n();

// Listing names open a preview modal rather than navigating away — on a
// phone, leaving the page loses the traveler's place in the itinerary.
const previewOpen = ref(false);

// The time reads as plain text until it's actually being changed; a
// permanently mounted <input type="time"> put a boxed form control on every
// row for something most travelers never touch.
const editingTime = ref(false);
const timeInput = ref<HTMLInputElement | null>(null);

async function startTimeEdit() {
    if (props.readonly) {
        return;
    }

    editingTime.value = true;
    await nextTick();
    timeInput.value?.focus();

    // Opens the browser's own time picker straight away, so one tap gets the
    // traveler to the wheel/clock instead of two. Not supported everywhere —
    // where it isn't, the focused input is still fully typeable.
    try {
        timeInput.value?.showPicker();
    } catch {
        // Ignore: unsupported, or refused because the input wasn't
        // considered user-activated. Focus alone is enough.
    }
}

function onTimeInput(event: Event) {
    emit('update:time', (event.target as HTMLInputElement).value || null);
}

const icon = computed(() => (props.type === 'activity' ? '📷' : '🍴'));

// Every row in the plan states a price. Plenty of scraped listings carry no
// rate yet, and silently omitting it there reads either as "included" or as a
// bug — "on request" is the honest version and keeps the column from looking
// ragged down the day.
const priceLabel = computed(
    () => formatPrice(props.item.price_from) ?? t('itinerary.priceOnRequest'),
);

// The second line: what the traveler is actually booking, in the order it
// matters. Kept as a list so booking-time facts (reference number, pickup
// point, confirmation state) can slot in here once inquiries are linked to
// plan entries — see TRAVEL_PLAN.md.
const details = computed(() => {
    const parts: string[] = [t(`itinerary.entryType.${props.type}`)];

    const duration = formatDuration(props.item.duration_minutes, t);

    if (duration) {
        parts.push(duration);
    }

    if (props.type === 'restaurant' && props.time) {
        parts.push(t(`itinerary.meal.${mealOfDay(props.time)}`));
    }

    return parts;
});

// Which meal a restaurant entry is, derived from its own time rather than
// stored — the plan only ever records when the traveler eats, and naming it
// "Dinner" at 08:00 would be worse than saying nothing.
function mealOfDay(time: string): 'breakfast' | 'lunch' | 'dinner' {
    const hour = Number(time.slice(0, 2));

    if (hour < 11) {
        return 'breakfast';
    }

    return hour < 16 ? 'lunch' : 'dinner';
}

const menuItems = computed(() => {
    const items: { key: string; label: string; danger?: boolean }[] = [];

    if (props.item.slug) {
        items.push({ key: 'details', label: t('itinerary.viewDetails') });
    }

    if (!props.readonly) {
        items.push({ key: 'time', label: t('itinerary.changeTime') });

        if (props.item.id) {
            items.push({ key: 'swap', label: t('itinerary.change') });
        }

        items.push({
            key: 'delete',
            label: t('itinerary.remove'),
            danger: true,
        });
    }

    return items;
});

function onMenuSelect(key: string) {
    if (key === 'details') {
        previewOpen.value = true;
    } else if (key === 'time') {
        startTimeEdit();
    } else if (key === 'swap') {
        emit('swap');
    } else if (key === 'delete') {
        emit('remove');
    }
}
</script>

<template>
    <div class="entry-row">
        <input
            v-if="editingTime"
            ref="timeInput"
            type="time"
            class="entry-row-time entry-row-time--input"
            :value="time ?? ''"
            :aria-label="t('itinerary.entryTime')"
            @input="onTimeInput"
            @blur="editingTime = false"
        />
        <button
            v-else
            type="button"
            class="entry-row-time"
            :class="{ 'entry-row-time--empty': !time }"
            :aria-label="t('itinerary.entryTime')"
            :disabled="readonly"
            @click="startTimeEdit"
        >
            {{ time || '--:--' }}
        </button>

        <span class="entry-row-icon" :class="`entry-row-icon--${type}`">{{
            icon
        }}</span>

        <div class="entry-row-main">
            <div class="entry-row-title">
                <button
                    v-if="item.slug"
                    type="button"
                    class="line-item-link"
                    @click="previewOpen = true"
                >
                    {{ item.name }}
                </button>
                <template v-else>{{ item.name }}</template>
                <span class="item-price">{{ priceLabel }}</span>
            </div>
            <div class="entry-row-details">
                <template v-for="(part, i) in details" :key="part">
                    <span v-if="i > 0" class="entry-row-details-sep">·</span
                    >{{ part }}
                </template>
            </div>
        </div>

        <KebabMenu
            v-if="menuItems.length"
            :items="menuItems"
            :label="t('itinerary.moreOptions')"
            @select="onMenuSelect"
        />
    </div>

    <ListingPreviewModal
        v-if="previewOpen && item.slug"
        :slug="item.slug"
        @close="previewOpen = false"
    />
</template>
