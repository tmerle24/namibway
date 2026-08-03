<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatPrice } from '@/lib/currency';
import type { RoomOption } from '@/lib/kaia-types';

const props = defineProps<{
    basePrice: string | null;
    currency: string;
    adults: number;
    children: number;
}>();

const emit = defineEmits<{
    (e: 'select', option: RoomOption): void;
}>();

const { t } = useI18n();

// Placeholder content, not a live availability/rate lookup — see the
// room_selection field's doc comment in kaia-types.ts. Scaled off the
// accommodation's own price_from so the numbers feel plausible per lodge
// rather than one fixed price everywhere.
const options = computed<RoomOption[]>(() => {
    const base = props.basePrice ? Number(props.basePrice) : 1200;
    const totalGuests = Math.max(props.adults + props.children, 2);

    return [
        {
            code: 'standard',
            name: t('itinerary.roomTypes.standard'),
            capacity: t('itinerary.meta.adults', 2),
            price_per_night: Math.round(base),
            currency: props.currency,
        },
        {
            code: 'comfort',
            name: t('itinerary.roomTypes.comfort'),
            capacity: [
                t(
                    'itinerary.meta.adults',
                    Math.min(props.adults || 2, totalGuests),
                ),
                props.children
                    ? t('itinerary.meta.children', props.children)
                    : null,
            ]
                .filter(Boolean)
                .join(', '),
            price_per_night: Math.round(base * 1.35),
            currency: props.currency,
        },
        {
            code: 'family',
            name: t('itinerary.roomTypes.family'),
            capacity: t('itinerary.meta.adults', totalGuests + 1),
            price_per_night: Math.round(base * 1.75),
            currency: props.currency,
        },
    ];
});
</script>

<template>
    <div class="room-options-panel">
        <div class="alternatives-title">{{ t('itinerary.chooseRoom') }}</div>
        <div class="room-options-note">
            {{ t('itinerary.roomPlaceholderNote') }}
        </div>
        <button
            v-for="option in options"
            :key="option.code"
            type="button"
            class="alternative-item room-option-item"
            @click="emit('select', option)"
        >
            <span class="alt-name">{{ option.name }}</span>
            <span class="room-option-capacity">{{ option.capacity }}</span>
            <span class="alt-price">{{
                formatPrice(String(option.price_per_night))
            }}</span>
            <span class="alt-use-btn">{{ t('itinerary.useThis') }}</span>
        </button>
    </div>
</template>

<style scoped>
.room-options-panel {
    margin: 4px 0 6px 0;
    border-left: 2px solid var(--sand-dark);
    padding-left: 10px;
}
.room-options-note {
    font-size: 11px;
    color: #a89a7e;
    font-style: italic;
    margin: -2px 0 6px;
}
.room-option-item {
    flex-wrap: wrap;
}
.room-option-capacity {
    font-size: 11.5px;
    color: #8a7f68;
}
</style>
