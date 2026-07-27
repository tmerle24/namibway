<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import draggable from 'vuedraggable';
import { fetchAlternatives, fetchRegions } from '@/lib/kaia-client';
import type { ItineraryListingRef, ItineraryPlan, ItineraryVariant } from '@/lib/kaia-types';
import AlternativesPanel from './AlternativesPanel.vue';
import ItineraryLineItem from './ItineraryLineItem.vue';
import LocationPicker from './LocationPicker.vue';

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
const swap = ref<SwapState | null>(null);
const dbRegions = ref<string[]>([]);

watch(
    () => props.plan,
    (plan) => {
        editableVariants.value = JSON.parse(JSON.stringify(plan.variants));
        swap.value = null;
    },
    { immediate: true },
);

onMounted(async () => {
    dbRegions.value = await fetchRegions();
});

// Combine DB regions with locations already in the plan — deduplicated
const locationSuggestions = computed(() => {
    const planLocations = editableVariants.value
        .flatMap(v => v.days.map(d => d.location))
        .filter(Boolean);

    return [...new Set([...planLocations, ...dbRegions.value])].sort();
});

function renumberDays(variantIndex: number) {
    editableVariants.value[variantIndex].days.forEach((day, index) => {
        day.day = index + 1;
    });
}

function removeItem(variantIndex: number, dayIndex: number, field: 'accommodation' | 'activity' | 'restaurant') {
    editableVariants.value[variantIndex].days[dayIndex][field] = null;
    swap.value = null;
}

function removeDay(variantIndex: number, dayIndex: number) {
    const days = editableVariants.value[variantIndex].days;
    days.splice(dayIndex, 1);
    days.forEach((day, index) => {
        day.day = index + 1;
    });
    swap.value = null;
}

// afterDayIndex = -1 inserts before the first day
function addDay(variantIndex: number, afterDayIndex: number) {
    const days = editableVariants.value[variantIndex].days;
    const prevLocation = afterDayIndex >= 0 ? (days[afterDayIndex]?.location ?? '') : '';
    days.splice(afterDayIndex + 1, 0, { day: 0, location: prevLocation, accommodation: null, activity: null, restaurant: null });
    days.forEach((day, index) => {
        day.day = index + 1;
    });
    swap.value = null;
}

// --- Swap / Add panel ---

type SwapField = 'accommodation' | 'activity' | 'restaurant' | 'vehicle';

interface SwapState {
    key: string;
    variantIndex: number;
    dayIndex: number | null;
    field: SwapField;
    loading: boolean;
    alternatives: ItineraryListingRef[];
}

function swapKey(variantIndex: number, dayIndex: number | null, field: SwapField): string {
    return `${variantIndex}-${dayIndex ?? 'v'}-${field}`;
}

// itemRef is undefined when opening as "add" (empty slot), defined when swapping an existing item.
async function openSwap(variantIndex: number, dayIndex: number | null, field: SwapField, itemRef?: ItineraryListingRef) {
    const key = swapKey(variantIndex, dayIndex, field);

    if (swap.value?.key === key) {
        swap.value = null;

        return;
    }

    swap.value = { key, variantIndex, dayIndex, field, loading: true, alternatives: [] };

    const results = await fetchAlternatives(field, itemRef?.id ?? undefined);

    if (swap.value?.key === key) {
        swap.value.loading = false;
        swap.value.alternatives = results;
    }
}

function applySwap(alternative: ItineraryListingRef) {
    if (!swap.value) {
        return;
    }

    const { variantIndex, dayIndex, field } = swap.value;
    const variant = editableVariants.value[variantIndex];

    if (field === 'vehicle') {
        variant.vehicle = alternative;
    } else if (dayIndex !== null) {
        variant.days[dayIndex][field] = alternative;
    }

    swap.value = null;
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

                <template v-if="variant.vehicle">
                    <ItineraryLineItem keypath="itinerary.vehicle" :item-ref="variant.vehicle" class="variant-vehicle"
                        @remove="variant.vehicle = null"
                        @swap="openSwap(variantIndex, null, 'vehicle', variant.vehicle!)" />
                    <AlternativesPanel
                        v-if="swap?.key === swapKey(variantIndex, null, 'vehicle')"
                        :loading="swap.loading"
                        :alternatives="swap.alternatives"
                        @select="applySwap" />
                </template>

                <button type="button" class="add-day-btn" @click="addDay(variantIndex, -1)">+ {{ t('itinerary.addDay') }}</button>

                <draggable
                    v-model="editableVariants[variantIndex].days"
                    item-key="day"
                    handle=".drag-handle"
                    :animation="150"
                    ghost-class="day-row--ghost"
                    @end="renumberDays(variantIndex)"
                >
                    <template #item="{ element: day, index: dayIndex }">
                        <div class="day-row">
                            <div class="day-num">
                                <span class="drag-handle" :title="t('itinerary.dragToReorder')">⠿</span>
                                {{ day.day }}
                            </div>
                            <div class="day-detail">
                                <div>
                                    <LocationPicker
                                        :model-value="day.location"
                                        :suggestions="locationSuggestions"
                                        @update:model-value="editableVariants[variantIndex].days[dayIndex].location = $event"
                                    />
                                    <button type="button" class="remove-btn" :aria-label="t('itinerary.removeDay')" @click="removeDay(variantIndex, dayIndex)">×</button>
                                </div>

                                <ItineraryLineItem keypath="itinerary.stay" :item-ref="day.accommodation"
                                    @remove="removeItem(variantIndex, dayIndex, 'accommodation')"
                                    @swap="openSwap(variantIndex, dayIndex, 'accommodation', day.accommodation!)"
                                    @add="openSwap(variantIndex, dayIndex, 'accommodation')" />
                                <AlternativesPanel
                                    v-if="swap?.key === swapKey(variantIndex, dayIndex, 'accommodation')"
                                    :loading="swap.loading"
                                    :alternatives="swap.alternatives"
                                    @select="applySwap" />

                                <ItineraryLineItem keypath="itinerary.activity" :item-ref="day.activity"
                                    @remove="removeItem(variantIndex, dayIndex, 'activity')"
                                    @swap="openSwap(variantIndex, dayIndex, 'activity', day.activity!)"
                                    @add="openSwap(variantIndex, dayIndex, 'activity')" />
                                <AlternativesPanel
                                    v-if="swap?.key === swapKey(variantIndex, dayIndex, 'activity')"
                                    :loading="swap.loading"
                                    :alternatives="swap.alternatives"
                                    @select="applySwap" />

                                <ItineraryLineItem keypath="itinerary.dinner" :item-ref="day.restaurant"
                                    @remove="removeItem(variantIndex, dayIndex, 'restaurant')"
                                    @swap="openSwap(variantIndex, dayIndex, 'restaurant', day.restaurant!)"
                                    @add="openSwap(variantIndex, dayIndex, 'restaurant')" />
                                <AlternativesPanel
                                    v-if="swap?.key === swapKey(variantIndex, dayIndex, 'restaurant')"
                                    :loading="swap.loading"
                                    :alternatives="swap.alternatives"
                                    @select="applySwap" />
                            </div>
                        </div>
                    </template>
                </draggable>

                <button type="button" class="add-day-btn" @click="addDay(variantIndex, variant.days.length - 1)">+ {{ t('itinerary.addDay') }}</button>

                <button class="cta" @click="emit('book', variant)">{{ t('itinerary.bookCta') }}</button>
            </div>
        </div>
    </section>
</template>
