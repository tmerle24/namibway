<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import draggable from 'vuedraggable';
import { formatPrice } from '@/lib/currency';
import {
    fetchAlternatives,
    fetchRegionCoords,
    fetchRegions,
    savePlan,
} from '@/lib/kaia-client';
import type { RegionCoords } from '@/lib/kaia-client';
import type {
    ItineraryListingRef,
    ItineraryPlan,
    ItineraryVariant,
} from '@/lib/kaia-types';
import AlternativesPanel from './AlternativesPanel.vue';
import ItineraryLineItem from './ItineraryLineItem.vue';
import LocationPicker from './LocationPicker.vue';
import SaveLoginModal from './SaveLoginModal.vue';
import SaveShareBar from './SaveShareBar.vue';
import TripMap from './TripMap.vue';
import type { DrivingLeg } from './TripMap.vue';

const props = defineProps<{
    plan: ItineraryPlan;
}>();

const emit = defineEmits<{
    (e: 'book', variant: ItineraryVariant): void;
}>();

const { t } = useI18n();
const page = usePage();
const isLoggedIn = computed(() => !!page.props.auth?.user);

// A local, editable copy — the traveler can remove a day or a single item
// (accommodation/activity/restaurant) without another round-trip to Kaia.
// Resets whenever a genuinely new plan comes in from the parent.
const editableVariants = ref<ItineraryVariant[]>([]);
const swap = ref<SwapState | null>(null);
const dbRegions = ref<string[]>([]);
const regionCoords = ref<Record<string, RegionCoords>>({});
const savedTokens = ref<Record<number, string>>({});
const drivingLegsPerVariant = ref<Record<number, DrivingLeg[]>>({});

// Trip-level start/end — same for every variant, editable inline like a
// day's location. Reversing a variant's direction (below) doesn't touch
// these; it only reorders that variant's own days.
const routeStart = ref('Windhoek');
const routeEnd = ref('Windhoek');
const isRoundTrip = computed(
    () =>
        routeStart.value.trim().toLowerCase() ===
        routeEnd.value.trim().toLowerCase(),
);

// --- Auth-gate for saving ---
const showAuthModal = ref(false);

// Track the start date per variant so we can recompute day dates after
// drag-and-drop reordering or manual day additions.
const startDates = ref<(Date | null)[]>([]);

function formatDrivingTime(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);

    if (h === 0) {
        return `${m} min`;
    }

    if (m === 0) {
        return `${h} h`;
    }

    return `${h} h ${m} min`;
}

function drivingTimeBetween(
    variantIndex: number,
    fromLocation: string,
    toLocation: string,
): string | null {
    const legs = drivingLegsPerVariant.value[variantIndex];

    if (!legs) {
        return null;
    }

    const leg = legs.find(
        (l) =>
            l.from.toLowerCase().trim() === fromLocation.toLowerCase().trim() &&
            l.to.toLowerCase().trim() === toLocation.toLowerCase().trim(),
    );

    return leg ? formatDrivingTime(leg.seconds) : null;
}

function parseDayDate(dateStr: string | null | undefined): Date | null {
    if (!dateStr) {
        return null;
    }

    const d = new Date(dateStr);

    return isNaN(d.getTime()) ? null : d;
}

function formatDayDate(date: Date): string {
    return date.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

// A single date per day is ambiguous about which night it covers ("28 Aug" —
// arriving that day, or already there?) — show the check-in/check-out range
// instead. Dates are "D Mon YYYY" (e.g. "15 Aug 2026"); drop the repeated
// month/year on the first half when it matches the second half.
function formatDateRange(day: {
    date?: string | null;
    date_to?: string | null;
}): string {
    if (!day.date) {
        return '';
    }

    if (!day.date_to) {
        return day.date;
    }

    const [fromDay, fromMonth, fromYear] = day.date.split(' ');
    const [, toMonth, toYear] = day.date_to.split(' ');

    if (fromMonth === toMonth && fromYear === toYear) {
        return `${fromDay} – ${day.date_to}`;
    }

    if (fromYear === toYear) {
        return `${fromDay} ${fromMonth} – ${day.date_to}`;
    }

    return `${day.date} – ${day.date_to}`;
}

function applyDates(variantIndex: number) {
    const start = startDates.value[variantIndex];

    if (!start) {
        return;
    }

    editableVariants.value[variantIndex].days.forEach((day, i) => {
        const d = new Date(start);
        d.setDate(d.getDate() + i);
        day.date = formatDayDate(d);

        const dTo = new Date(start);
        dTo.setDate(dTo.getDate() + i + 1);
        day.date_to = formatDayDate(dTo);
    });
}

function onStartDateInput(variantIndex: number, value: string) {
    if (!value) {
        return;
    }

    const d = new Date(value);

    if (isNaN(d.getTime())) {
        return;
    }

    startDates.value[variantIndex] = d;
    applyDates(variantIndex);
}

watch(
    () => props.plan,
    (plan) => {
        editableVariants.value = JSON.parse(JSON.stringify(plan.variants));
        startDates.value = plan.variants.map((v) =>
            parseDayDate(v.days[0]?.date),
        );
        routeStart.value = plan.start_location || 'Windhoek';
        routeEnd.value = plan.end_location || routeStart.value;
        swap.value = null;

        // Claude doesn't always fill in every day's date field consistently —
        // normalize all days from day 1's date right away rather than only
        // after the traveler drags/adds/removes something.
        plan.variants.forEach((_, i) => applyDates(i));
    },
    { immediate: true },
);

onMounted(async () => {
    [dbRegions.value, regionCoords.value] = await Promise.all([
        fetchRegions(),
        fetchRegionCoords(),
    ]);
});

// Combine DB regions with locations already in the plan — deduplicated
const locationSuggestions = computed(() => {
    const planLocations = editableVariants.value
        .flatMap((v) => v.days.map((d) => d.location))
        .filter(Boolean);

    return [...new Set([...planLocations, ...dbRegions.value])].sort();
});

function renumberDays(variantIndex: number) {
    editableVariants.value[variantIndex].days.forEach((day, index) => {
        day.day = index + 1;
    });
    applyDates(variantIndex);
}

// The cheap "do the same loop backwards" alternative Till asked for — no
// second Kaia call, just reorder the days this variant already has and
// reuse the existing renumber/date helpers.
function reverseVariant(variantIndex: number) {
    editableVariants.value[variantIndex].days.reverse();
    renumberDays(variantIndex);
    swap.value = null;
}

function removeItem(
    variantIndex: number,
    dayIndex: number,
    field: 'accommodation' | 'activity' | 'restaurant',
) {
    editableVariants.value[variantIndex].days[dayIndex][field] = null;
    swap.value = null;
}

function removeDay(variantIndex: number, dayIndex: number) {
    const days = editableVariants.value[variantIndex].days;
    days.splice(dayIndex, 1);
    days.forEach((day, index) => {
        day.day = index + 1;
    });
    applyDates(variantIndex);
    swap.value = null;
}

// afterDayIndex = -1 inserts before the first day
function addDay(variantIndex: number, afterDayIndex: number) {
    const days = editableVariants.value[variantIndex].days;
    const prevLocation =
        afterDayIndex >= 0 ? (days[afterDayIndex]?.location ?? '') : '';
    days.splice(afterDayIndex + 1, 0, {
        day: 0,
        location: prevLocation,
        accommodation: null,
        activity: null,
        restaurant: null,
    });
    days.forEach((day, index) => {
        day.day = index + 1;
    });
    applyDates(variantIndex);
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

function swapKey(
    variantIndex: number,
    dayIndex: number | null,
    field: SwapField,
): string {
    return `${variantIndex}-${dayIndex ?? 'v'}-${field}`;
}

// itemRef is undefined when opening as "add" (empty slot), defined when swapping an existing item.
async function openSwap(
    variantIndex: number,
    dayIndex: number | null,
    field: SwapField,
    itemRef?: ItineraryListingRef,
) {
    const key = swapKey(variantIndex, dayIndex, field);

    if (swap.value?.key === key) {
        swap.value = null;

        return;
    }

    swap.value = {
        key,
        variantIndex,
        dayIndex,
        field,
        loading: true,
        alternatives: [],
    };

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

// --- Save & share (auth-gated) ---

// Called by SaveShareBar when the user isn't logged in
function onNeedAuth() {
    showAuthModal.value = true;
}

// After successful login in the modal: save ALL variants so none is lost
async function onAuthSuccess() {
    showAuthModal.value = false;
    await saveAllVariants();
}

async function saveAllVariants() {
    const results = await Promise.allSettled(
        editableVariants.value.map((variant, i) =>
            savePlan({
                trip_summary: props.plan.trip_summary,
                variants: [variant],
                start_location: routeStart.value,
                end_location: routeEnd.value,
            }).then((result) => {
                savedTokens.value[i] = result.token;
            }),
        ),
    );
    // Log any failures silently — the UI will keep the Save button for failed ones
    results.forEach((r, i) => {
        if (r.status === 'rejected') {
            console.warn(`Failed to save variant ${i}:`, r.reason);
        }
    });
}

function estimatedLabel(variant: ItineraryVariant): string | null {
    let amount = 0;
    let hasAnyPrice = false;

    for (const day of variant.days) {
        for (const item of [day.accommodation, day.activity, day.restaurant]) {
            if (item?.price_from) {
                amount += Number(item.price_from);
                hasAnyPrice = true;
            }
        }
    }

    if (variant.vehicle?.price_from) {
        amount += Number(variant.vehicle.price_from) * variant.days.length;
        hasAnyPrice = true;
    }

    if (!hasAnyPrice) {
        return null;
    }

    return t('itinerary.estimated', { price: formatPrice(amount) });
}
</script>

<template>
    <section id="itinerary-section">
        <div class="section-head">
            <div class="eyebrow">{{ t('itinerary.eyebrow') }}</div>
            <h2>{{ t('itinerary.title') }}</h2>
            <p>{{ t('itinerary.subtitle') }}</p>
            <div class="route-summary">
                <span class="route-label">{{ t('itinerary.route') }}:</span>
                <LocationPicker
                    :model-value="routeStart"
                    :suggestions="locationSuggestions"
                    @update:model-value="routeStart = $event"
                />
                <span class="route-arrow">→</span>
                <LocationPicker
                    :model-value="routeEnd"
                    :suggestions="locationSuggestions"
                    @update:model-value="routeEnd = $event"
                />
                <span v-if="isRoundTrip" class="route-roundtrip-tag">{{
                    t('itinerary.roundTrip')
                }}</span>
            </div>
        </div>
        <div class="variants">
            <div
                v-for="(variant, variantIndex) in editableVariants"
                :key="variant.name"
                class="variant-card"
            >
                <div class="variant-head">
                    <h3>{{ variant.name }}</h3>
                    <button
                        type="button"
                        class="reverse-route-btn"
                        @click="reverseVariant(variantIndex)"
                    >
                        ⇄ {{ t('itinerary.reverseRoute') }}
                    </button>
                </div>
                <div v-if="estimatedLabel(variant)" class="variant-price">
                    {{ estimatedLabel(variant) }}
                </div>

                <template v-if="variant.vehicle">
                    <ItineraryLineItem
                        keypath="itinerary.vehicle"
                        :item-ref="variant.vehicle"
                        class="variant-vehicle"
                        @remove="variant.vehicle = null"
                        @swap="
                            openSwap(
                                variantIndex,
                                null,
                                'vehicle',
                                variant.vehicle!,
                            )
                        "
                    />
                    <AlternativesPanel
                        v-if="
                            swap?.key === swapKey(variantIndex, null, 'vehicle')
                        "
                        :loading="swap.loading"
                        :alternatives="swap.alternatives"
                        @select="applySwap"
                    />
                </template>

                <div v-if="!startDates[variantIndex]" class="start-date-prompt">
                    <label class="start-date-label">
                        {{ t('itinerary.setStartDate') }}:
                        <input
                            type="date"
                            class="start-date-input"
                            @change="
                                onStartDateInput(
                                    variantIndex,
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                    </label>
                </div>

                <TripMap
                    :map-id="`trip-map-${variantIndex}`"
                    :variant="variant"
                    :region-coords="regionCoords"
                    @driving-legs="
                        (legs) => {
                            drivingLegsPerVariant[variantIndex] = legs;
                        }
                    "
                />

                <button
                    type="button"
                    class="add-day-btn"
                    @click="addDay(variantIndex, -1)"
                >
                    + {{ t('itinerary.addDay') }}
                </button>

                <draggable
                    v-model="editableVariants[variantIndex].days"
                    item-key="day"
                    handle=".drag-handle"
                    :animation="150"
                    ghost-class="day-row--ghost"
                    @end="renumberDays(variantIndex)"
                >
                    <template #item="{ element: day, index: dayIndex }">
                        <div
                            v-if="
                                dayIndex > 0 &&
                                day.location !==
                                    editableVariants[variantIndex].days[
                                        dayIndex - 1
                                    ].location &&
                                drivingTimeBetween(
                                    variantIndex,
                                    editableVariants[variantIndex].days[
                                        dayIndex - 1
                                    ].location,
                                    day.location,
                                )
                            "
                            class="drive-time-row"
                        >
                            <span class="drive-time-icon">🚗</span>
                            <span class="drive-time-label">
                                {{ t('itinerary.drivingTime') }}:
                                {{
                                    drivingTimeBetween(
                                        variantIndex,
                                        editableVariants[variantIndex].days[
                                            dayIndex - 1
                                        ].location,
                                        day.location,
                                    )
                                }}
                            </span>
                        </div>
                        <div class="day-row">
                            <div class="day-num">
                                <span
                                    class="drag-handle"
                                    :title="t('itinerary.dragToReorder')"
                                    >⠿</span
                                >
                                {{ day.day }}
                                <span v-if="day.date" class="day-date">{{
                                    formatDateRange(day)
                                }}</span>
                            </div>
                            <div class="day-detail">
                                <div>
                                    <LocationPicker
                                        :model-value="day.location"
                                        :suggestions="locationSuggestions"
                                        @update:model-value="
                                            editableVariants[variantIndex].days[
                                                dayIndex
                                            ].location = $event
                                        "
                                    />
                                    <button
                                        type="button"
                                        class="remove-btn"
                                        :aria-label="t('itinerary.removeDay')"
                                        @click="
                                            removeDay(variantIndex, dayIndex)
                                        "
                                    >
                                        ×
                                    </button>
                                </div>

                                <ItineraryLineItem
                                    keypath="itinerary.stay"
                                    :item-ref="day.accommodation"
                                    @remove="
                                        removeItem(
                                            variantIndex,
                                            dayIndex,
                                            'accommodation',
                                        )
                                    "
                                    @swap="
                                        openSwap(
                                            variantIndex,
                                            dayIndex,
                                            'accommodation',
                                            day.accommodation!,
                                        )
                                    "
                                    @add="
                                        openSwap(
                                            variantIndex,
                                            dayIndex,
                                            'accommodation',
                                        )
                                    "
                                />
                                <AlternativesPanel
                                    v-if="
                                        swap?.key ===
                                        swapKey(
                                            variantIndex,
                                            dayIndex,
                                            'accommodation',
                                        )
                                    "
                                    :loading="swap.loading"
                                    :alternatives="swap.alternatives"
                                    @select="applySwap"
                                />

                                <ItineraryLineItem
                                    keypath="itinerary.activity"
                                    :item-ref="day.activity"
                                    @remove="
                                        removeItem(
                                            variantIndex,
                                            dayIndex,
                                            'activity',
                                        )
                                    "
                                    @swap="
                                        openSwap(
                                            variantIndex,
                                            dayIndex,
                                            'activity',
                                            day.activity!,
                                        )
                                    "
                                    @add="
                                        openSwap(
                                            variantIndex,
                                            dayIndex,
                                            'activity',
                                        )
                                    "
                                />
                                <AlternativesPanel
                                    v-if="
                                        swap?.key ===
                                        swapKey(
                                            variantIndex,
                                            dayIndex,
                                            'activity',
                                        )
                                    "
                                    :loading="swap.loading"
                                    :alternatives="swap.alternatives"
                                    @select="applySwap"
                                />

                                <ItineraryLineItem
                                    keypath="itinerary.dinner"
                                    :item-ref="day.restaurant"
                                    @remove="
                                        removeItem(
                                            variantIndex,
                                            dayIndex,
                                            'restaurant',
                                        )
                                    "
                                    @swap="
                                        openSwap(
                                            variantIndex,
                                            dayIndex,
                                            'restaurant',
                                            day.restaurant!,
                                        )
                                    "
                                    @add="
                                        openSwap(
                                            variantIndex,
                                            dayIndex,
                                            'restaurant',
                                        )
                                    "
                                />
                                <AlternativesPanel
                                    v-if="
                                        swap?.key ===
                                        swapKey(
                                            variantIndex,
                                            dayIndex,
                                            'restaurant',
                                        )
                                    "
                                    :loading="swap.loading"
                                    :alternatives="swap.alternatives"
                                    @select="applySwap"
                                />
                            </div>
                        </div>
                    </template>
                </draggable>

                <button
                    type="button"
                    class="add-day-btn"
                    @click="addDay(variantIndex, variant.days.length - 1)"
                >
                    + {{ t('itinerary.addDay') }}
                </button>

                <button class="cta" @click="emit('book', variant)">
                    {{ t('itinerary.bookCta') }}
                </button>

                <SaveShareBar
                    :plan="{
                        trip_summary: plan.trip_summary,
                        variants: [variant],
                        start_location: routeStart,
                        end_location: routeEnd,
                    }"
                    :token="savedTokens[variantIndex] ?? null"
                    :is-logged-in="isLoggedIn"
                    @saved="
                        (token) => {
                            savedTokens[variantIndex] = token;
                        }
                    "
                    @need-auth="onNeedAuth"
                />
            </div>
        </div>

        <SaveLoginModal
            v-if="showAuthModal"
            @close="showAuthModal = false"
            @authenticated="onAuthSuccess"
        />
    </section>
</template>
