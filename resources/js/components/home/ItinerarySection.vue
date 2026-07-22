<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ItineraryPlan, ItineraryVariant } from '@/lib/kaia-types';
import ItineraryLineItem from './ItineraryLineItem.vue';

const props = defineProps<{
    plan: ItineraryPlan;
}>();

const emit = defineEmits<{
    (e: 'book', variant: ItineraryVariant): void;
}>();

const { t } = useI18n();

// A local, editable copy — the traveler can remove a day or a single item
// (accommodation/activity/restaurant) without another round-trip to Kaia.
// Resets whenever a genuinely new plan comes in from the parent.
const editableVariants = ref<ItineraryVariant[]>([]);

watch(
    () => props.plan,
    (plan) => {
        editableVariants.value = JSON.parse(JSON.stringify(plan.variants));
    },
    { immediate: true },
);

function removeItem(variantIndex: number, dayIndex: number, field: 'accommodation' | 'activity' | 'restaurant') {
    editableVariants.value[variantIndex].days[dayIndex][field] = null;
}

function removeDay(variantIndex: number, dayIndex: number) {
    const days = editableVariants.value[variantIndex].days;
    days.splice(dayIndex, 1);
    days.forEach((day, index) => {
        day.day = index + 1;
    });
}

function estimatedLabel(variant: ItineraryVariant): string | null {
    let amount = 0;
    let currency: string | null = null;

    for (const day of variant.days) {
        for (const item of [day.accommodation, day.activity, day.restaurant]) {
            if (item?.price_from) {
                amount += Number(item.price_from);
                currency ??= item.price_currency;
            }
        }
    }

    if (variant.vehicle?.price_from) {
        amount += Number(variant.vehicle.price_from) * variant.days.length;
        currency ??= variant.vehicle.price_currency;
    }

    if (currency === null) {
        return null;
    }

    return t('itinerary.estimated', { amount: Math.round(amount).toLocaleString(), currency });
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
            <div v-for="(variant, variantIndex) in editableVariants" :key="variant.name" class="variant-card">
                <h3>{{ variant.name }}</h3>
                <div v-if="estimatedLabel(variant)" class="variant-price">{{ estimatedLabel(variant) }}</div>
                <ItineraryLineItem v-if="variant.vehicle" keypath="itinerary.vehicle" :item-ref="variant.vehicle" class="variant-vehicle" @remove="variant.vehicle = null" />
                <div v-for="(day, dayIndex) in variant.days" :key="day.day" class="day-row">
                    <div class="day-num">{{ day.day }}</div>
                    <div class="day-detail">
                        <div>
                            <b>{{ day.location }}</b>
                            <button type="button" class="remove-btn" :aria-label="t('itinerary.removeDay')" @click="removeDay(variantIndex, dayIndex)">×</button>
                        </div>
                        <ItineraryLineItem keypath="itinerary.stay" :item-ref="day.accommodation" @remove="removeItem(variantIndex, dayIndex, 'accommodation')" />
                        <ItineraryLineItem keypath="itinerary.activity" :item-ref="day.activity" @remove="removeItem(variantIndex, dayIndex, 'activity')" />
                        <ItineraryLineItem keypath="itinerary.dinner" :item-ref="day.restaurant" @remove="removeItem(variantIndex, dayIndex, 'restaurant')" />
                    </div>
                </div>
                <button class="cta" @click="emit('book', variant)">{{ t('itinerary.bookCta') }}</button>
            </div>
        </div>
    </section>
</template>
