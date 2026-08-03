<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import draggable from 'vuedraggable';
import { formatPrice } from '@/lib/currency';
import {
    fetchAlternatives,
    fetchCities,
    fetchRegionCoords,
    fetchRegions,
    regeneratePlan,
    savePlan,
    updatePlan,
} from '@/lib/kaia-client';
import type { RegionCoords } from '@/lib/kaia-client';
import type {
    ItineraryListingRef,
    ItineraryPlan,
    ItineraryVariant,
    RoomOption,
    TripParams,
} from '@/lib/kaia-types';
import AlternativesPanel from './AlternativesPanel.vue';
import ItineraryLineItem from './ItineraryLineItem.vue';
import LocationPicker from './LocationPicker.vue';
import RoomTypePicker from './RoomTypePicker.vue';
import SaveLoginModal from './SaveLoginModal.vue';
import SaveShareBar from './SaveShareBar.vue';
import TripMap from './TripMap.vue';
import type { DrivingLeg } from './TripMap.vue';
import TripMeta from './TripMeta.vue';
import TripParamsEditModal from './TripParamsEditModal.vue';
import type { TripParamsFormValues } from './TripParamsEditModal.vue';

const props = defineProps<{
    plan: ItineraryPlan;
    token: string | null;
}>();

const emit = defineEmits<{
    (e: 'book', variant: ItineraryVariant): void;
    (e: 'update:token', token: string): void;
}>();

const { t } = useI18n();
const page = usePage();
const isLoggedIn = computed(() => !!page.props.auth?.user);

// A local, editable copy — the traveler can remove a day or a single item
// (accommodation/activity/restaurant) without another round-trip to Kaia.
// Resets whenever a genuinely new plan comes in from the parent.
const editableVariants = ref<ItineraryVariant[]>([]);
const swap = ref<SwapState | null>(null);
const roomPickerKey = ref<string | null>(null);
const dbRegions = ref<string[]>([]);
const dbCities = ref<string[]>([]);
const regionCoords = ref<Record<string, RegionCoords>>({});
const savedTokens = ref<Record<number, string>>({});
const drivingLegsPerVariant = ref<Record<number, DrivingLeg[]>>({});

// Whole-session token (all surviving variants together) — distinct from
// savedTokens above, which are per-variant tokens minted by the manual
// "Save & share" button. This one drives the ?trip= URL param so reloading
// or revisiting the link restores exactly what's in editableVariants now.
const currentToken = ref<string | null>(props.token);

// Trip-level start/end — same for every variant. Reversing a variant's
// direction (below) doesn't touch these; it only reorders that variant's
// own days. Edited together with the rest of the trip params via the
// params-edit popup (see openParamsEditor/applyParamsEdit below).
const routeStart = ref('Windhoek');
const routeEnd = ref('Windhoek');

// --- Auth-gate for saving ---
const showAuthModal = ref(false);

// Local, editable shadows of the plan-level (not per-variant) fields —
// same reasoning as editableVariants above: regenerating the plan from the
// params-edit popup replaces these without touching the read-only prop.
const currentTripSummary = ref(props.plan.trip_summary);
const currentTripParams = ref<TripParams | null | undefined>(
    props.plan.trip_params,
);

// --- Trip-params edit popup (regenerates the whole plan via Kaia) ---
const paramsModalOpen = ref(false);
const regenerating = ref(false);
const regenerateError = ref<string | null>(null);

function openParamsEditor() {
    regenerateError.value = null;
    paramsModalOpen.value = true;
}

function nightsBetween(fromStr: string, toStr: string): number {
    const from = parseDateInputValue(fromStr);
    const to = parseDateInputValue(toStr);

    return Math.round((to.getTime() - from.getTime()) / 86400000);
}

// Constructs a local-midnight Date from a <input type="date"> value —
// `new Date('YYYY-MM-DD')` parses as UTC midnight, which can roll back a
// day once formatted in a negative-UTC-offset timezone.
function parseDateInputValue(value: string): Date {
    const [y, m, d] = value.split('-').map(Number);

    return new Date(y, m - 1, d);
}

function formatTravelPeriod(fromStr: string, toStr: string): string {
    const fmt = (d: Date) =>
        d.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
    const fromFmt = fmt(parseDateInputValue(fromStr));
    const toFmt = fmt(parseDateInputValue(toStr));

    return fromFmt === toFmt ? fromFmt : `${fromFmt} – ${toFmt}`;
}

// The interview only ever asked for a headcount, not individual ages, but
// the edit popup lets the traveler enter ages directly — count how many
// fall under 13 from that free-text list ("5, 8, 15" -> 2).
function computeChildrenUnder13(childrenAges: string): number {
    return childrenAges
        .split(',')
        .map((s) => parseInt(s.trim(), 10))
        .filter((n) => !isNaN(n) && n < 13).length;
}

async function applyParamsEdit(values: TripParamsFormValues) {
    regenerateError.value = null;
    regenerating.value = true;

    try {
        const newPlan = await regeneratePlan({
            nights: Math.max(nightsBetween(values.dateFrom, values.dateTo), 1),
            travel_period: formatTravelPeriod(values.dateFrom, values.dateTo),
            interests: values.interests,
            budget_tier: values.budgetTier,
            adults: values.adults,
            children_under_13: computeChildrenUnder13(values.childrenAges),
            children_ages: values.childrenAges || null,
            vehicle_type: currentTripParams.value?.vehicle_type || 'car',
            start_location: values.startLocation,
            end_location: values.endLocation,
        });

        editableVariants.value = JSON.parse(JSON.stringify(newPlan.variants));
        startDates.value = newPlan.variants.map((v) =>
            parseDayDate(v.days[0]?.date),
        );
        routeStart.value = newPlan.start_location || values.startLocation;
        routeEnd.value = newPlan.end_location || routeStart.value;
        currentTripSummary.value = newPlan.trip_summary;
        currentTripParams.value = newPlan.trip_params;
        newPlan.variants.forEach((_, i) => applyDates(i));
        swap.value = null;
        roomPickerKey.value = null;
        savedTokens.value = {};
        drivingLegsPerVariant.value = {};
        paramsModalOpen.value = false;
    } catch (e) {
        regenerateError.value =
            e instanceof Error ? e.message : 'Could not update the plan.';
    } finally {
        regenerating.value = false;
    }
}

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
    fromLocation: string | null | undefined,
    toLocation: string | null | undefined,
): string | null {
    const legs = drivingLegsPerVariant.value[variantIndex];

    if (!legs || !fromLocation || !toLocation) {
        return null;
    }

    const from = fromLocation.toLowerCase().trim();
    const to = toLocation.toLowerCase().trim();

    const leg = legs.find(
        (l) =>
            l.from?.toLowerCase().trim() === from &&
            l.to?.toLowerCase().trim() === to,
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

// Accommodation photo first (what the traveler is actually booking), falling
// back to a representative photo for the day's region — regionCoords is
// keyed by both destination name and, since fetchRegionCoords()'s backing
// endpoint was extended for this, the plain political region name too (the
// only thing a day's `location` ever is).
function dayThumbnail(day: {
    location: string;
    accommodation?: { image?: string | null } | null;
}): string | null {
    if (day.accommodation?.image) {
        return day.accommodation.image;
    }

    const key = day.location?.toLowerCase().trim();

    return (key && regionCoords.value[key]?.image) || null;
}

// `day.location` is always a political region (never shown to travelers
// directly — see the AI contract). Prefer the actual city of the day's
// accommodation for display; older saved plans without a `city` on their
// listing refs fall back to the region.
function dayCity(day: {
    location: string;
    accommodation?: { city?: string | null } | null;
}): string {
    return day.accommodation?.city || day.location;
}

// A day starts a new "stage" (Etappe) when it's the first day, or its
// location differs from the previous day's — consecutive days sharing a
// location are one stage and only show the city heading once.
function isStageStart(variantIndex: number, dayIndex: number): boolean {
    if (dayIndex === 0) {
        return true;
    }

    const days = editableVariants.value[variantIndex].days;

    return days[dayIndex].location !== days[dayIndex - 1].location;
}

// The LocationPicker only renders on stage-start days, so `dayIndex` is
// always that run's first day — editing the city there moves every day in
// the stage together, keeping the (now-hidden) headings of days 2+ in sync.
function setStageLocation(
    variantIndex: number,
    dayIndex: number,
    newLocation: string,
) {
    const days = editableVariants.value[variantIndex].days;
    const oldLocation = days[dayIndex].location;

    for (
        let i = dayIndex;
        i < days.length && days[i].location === oldLocation;
        i++
    ) {
        days[i].location = newLocation;
    }

    swap.value = null;
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

// --- Auto-persist (whole-session token behind the ?trip= URL param) ---
// Declared ahead of the plan watcher below since that watcher runs
// synchronously (immediate: true) during setup and references these.

let skipNextPersist = false;
let persistTimer: ReturnType<typeof setTimeout> | null = null;
let persistInFlight = false;
let persistPending = false;

function schedulePersist() {
    if (skipNextPersist) {
        skipNextPersist = false;

        return;
    }

    if (persistTimer) {
        clearTimeout(persistTimer);
    }

    persistTimer = setTimeout(runPersist, 600);
}

async function runPersist() {
    if (persistInFlight) {
        persistPending = true;

        return;
    }

    persistInFlight = true;

    const combined: ItineraryPlan = {
        trip_summary: currentTripSummary.value,
        variants: editableVariants.value,
        start_location: routeStart.value,
        end_location: routeEnd.value,
        trip_params: currentTripParams.value,
    };

    try {
        if (currentToken.value) {
            await updatePlan(currentToken.value, combined);
        } else {
            const result = await savePlan(combined);
            currentToken.value = result.token;
            emit('update:token', result.token);
        }
    } catch (e) {
        console.warn('Failed to auto-save plan:', e);
    } finally {
        persistInFlight = false;

        if (persistPending) {
            persistPending = false;
            schedulePersist();
        }
    }
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
        currentTripSummary.value = plan.trip_summary;
        currentTripParams.value = plan.trip_params;
        swap.value = null;
        roomPickerKey.value = null;

        // Claude doesn't always fill in every day's date field consistently —
        // normalize all days from day 1's date right away rather than only
        // after the traveler drags/adds/removes something.
        plan.variants.forEach((_, i) => applyDates(i));

        // Only skip the immediate re-save when hydrating a plan that's
        // *already* got a token (restored via ?trip=token — saving it right
        // back would be a redundant round-trip). A brand-new Kaia result has
        // no token yet, so it must NOT be skipped — that's the save that
        // mints the token and updates the URL in the first place.
        skipNextPersist = !!currentToken.value;
    },
    { immediate: true },
);

watch(
    () => props.token,
    (token) => {
        if (token !== currentToken.value) {
            currentToken.value = token;
        }
    },
);

watch(
    [editableVariants, routeStart, routeEnd, currentTripParams],
    schedulePersist,
    { deep: true },
);

// Shifts the keys of an index-keyed record down by one past the removed
// index — savedTokens/drivingLegsPerVariant would otherwise point at the
// wrong (shifted) variant after a dismiss.
function reindexAfterRemoval<T>(
    record: Record<number, T>,
    removedIndex: number,
): Record<number, T> {
    const result: Record<number, T> = {};

    Object.entries(record).forEach(([key, value]) => {
        const i = Number(key);

        if (i < removedIndex) {
            result[i] = value;
        } else if (i > removedIndex) {
            result[i - 1] = value;
        }
    });

    return result;
}

function dismissVariant(variantIndex: number) {
    if (editableVariants.value.length <= 1) {
        return;
    }

    editableVariants.value.splice(variantIndex, 1);
    startDates.value.splice(variantIndex, 1);
    savedTokens.value = reindexAfterRemoval(savedTokens.value, variantIndex);
    drivingLegsPerVariant.value = reindexAfterRemoval(
        drivingLegsPerVariant.value,
        variantIndex,
    );
    swap.value = null;
    roomPickerKey.value = null;
}

onMounted(async () => {
    [dbRegions.value, regionCoords.value, dbCities.value] = await Promise.all([
        fetchRegions(),
        fetchRegionCoords(),
        fetchCities(),
    ]);
});

// Combine DB regions with locations already in the plan — deduplicated.
// Used for per-day location editing, where the underlying value must stay a
// political region (see dayCity()/LocationPicker's `label` prop).
const locationSuggestions = computed(() => {
    const planLocations = editableVariants.value
        .flatMap((v) => v.days.map((d) => d.location))
        .filter(Boolean);

    return [...new Set([...planLocations, ...dbRegions.value])].sort();
});

// Real city names (not regions) for the trip-wide Startort/Zielort fields.
const startEndLocationSuggestions = computed(() =>
    [...new Set([routeStart.value, routeEnd.value, ...dbCities.value])].sort(),
);

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
    roomPickerKey.value = null;
}

function removeItem(
    variantIndex: number,
    dayIndex: number,
    field: 'accommodation' | 'activity' | 'restaurant',
) {
    editableVariants.value[variantIndex].days[dayIndex][field] = null;
    swap.value = null;
    roomPickerKey.value = null;
}

function removeDay(variantIndex: number, dayIndex: number) {
    const days = editableVariants.value[variantIndex].days;
    days.splice(dayIndex, 1);
    days.forEach((day, index) => {
        day.day = index + 1;
    });
    applyDates(variantIndex);
    swap.value = null;
    roomPickerKey.value = null;
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
    roomPickerKey.value = null;
}

// --- Room type picker (placeholder content, see RoomTypePicker.vue) ---

function roomSelectionKey(variantIndex: number, dayIndex: number): string {
    return `${variantIndex}-${dayIndex}`;
}

function toggleRoomPicker(variantIndex: number, dayIndex: number) {
    const key = roomSelectionKey(variantIndex, dayIndex);
    roomPickerKey.value = roomPickerKey.value === key ? null : key;
}

function selectRoom(
    variantIndex: number,
    dayIndex: number,
    option: RoomOption,
) {
    editableVariants.value[variantIndex].days[dayIndex].room_selection = option;
    roomPickerKey.value = null;
}

function clearRoom(variantIndex: number, dayIndex: number) {
    editableVariants.value[variantIndex].days[dayIndex].room_selection = null;
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
        roomPickerKey.value = null;

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
    roomPickerKey.value = null;
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
                trip_summary: currentTripSummary.value,
                variants: [variant],
                start_location: routeStart.value,
                end_location: routeEnd.value,
                trip_params: currentTripParams.value,
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
            <p v-if="currentTripSummary" class="trip-summary-text">
                {{ currentTripSummary }}
            </p>
        </div>
        <div class="variants">
            <div
                v-for="(variant, variantIndex) in editableVariants"
                :key="variant.name"
                class="variant-card"
            >
                <div class="variant-head">
                    <h3>{{ variant.name }}</h3>
                    <div class="variant-head-actions">
                        <button
                            type="button"
                            class="reverse-route-btn"
                            @click="reverseVariant(variantIndex)"
                        >
                            ⇄ {{ t('itinerary.reverseRoute') }}
                        </button>
                        <button
                            v-if="editableVariants.length > 1"
                            type="button"
                            class="dismiss-variant-btn"
                            :aria-label="t('itinerary.dismissPlan')"
                            :title="t('itinerary.dismissPlan')"
                            @click="dismissVariant(variantIndex)"
                        >
                            ×
                        </button>
                    </div>
                </div>
                <div v-if="estimatedLabel(variant)" class="variant-price">
                    {{ estimatedLabel(variant) }}
                </div>

                <TripMeta
                    :trip-params="currentTripParams"
                    :route-start="routeStart"
                    :route-end="routeEnd"
                    editable
                    @edit="openParamsEditor"
                />
                <div v-if="regenerating" class="params-regenerating-note">
                    {{ t('itinerary.paramsEditor.saving') }}
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
                        <div class="day-item">
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
                                    <div class="day-num-top">
                                        <span
                                            class="drag-handle"
                                            :title="
                                                t('itinerary.dragToReorder')
                                            "
                                            >⠿</span
                                        >
                                        <span
                                            class="trip-map-marker day-num-badge"
                                            :class="{
                                                'trip-map-marker--start':
                                                    dayIndex === 0,
                                                'trip-map-marker--end':
                                                    dayIndex ===
                                                        editableVariants[
                                                            variantIndex
                                                        ].days.length -
                                                            1 &&
                                                    editableVariants[
                                                        variantIndex
                                                    ].days.length > 1,
                                            }"
                                            >{{ day.day }}</span
                                        >
                                    </div>
                                    <span v-if="day.date" class="day-date">{{
                                        formatDateRange(day)
                                    }}</span>
                                </div>
                                <img
                                    v-if="dayThumbnail(day)"
                                    :src="dayThumbnail(day)!"
                                    alt=""
                                    class="day-thumb"
                                />
                                <div
                                    class="day-detail"
                                    :class="{
                                        'day-detail--continuation':
                                            !isStageStart(
                                                variantIndex,
                                                dayIndex,
                                            ),
                                    }"
                                >
                                    <div>
                                        <LocationPicker
                                            v-if="
                                                isStageStart(
                                                    variantIndex,
                                                    dayIndex,
                                                )
                                            "
                                            :model-value="day.location"
                                            :label="dayCity(day)"
                                            :suggestions="locationSuggestions"
                                            @update:model-value="
                                                setStageLocation(
                                                    variantIndex,
                                                    dayIndex,
                                                    $event,
                                                )
                                            "
                                        />
                                        <button
                                            type="button"
                                            class="remove-btn"
                                            :aria-label="
                                                t('itinerary.removeDay')
                                            "
                                            @click="
                                                removeDay(
                                                    variantIndex,
                                                    dayIndex,
                                                )
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
                                    <div
                                        v-if="day.accommodation"
                                        class="room-selection-row"
                                    >
                                        <template v-if="day.room_selection">
                                            <span class="room-selection-chip">
                                                🛏️
                                                {{ day.room_selection.name }}
                                                ·
                                                {{
                                                    formatPrice(
                                                        String(
                                                            day.room_selection
                                                                .price_per_night,
                                                        ),
                                                    )
                                                }}/{{ t('itinerary.perNight') }}
                                            </span>
                                            <button
                                                type="button"
                                                class="room-selection-link"
                                                @click="
                                                    toggleRoomPicker(
                                                        variantIndex,
                                                        dayIndex,
                                                    )
                                                "
                                            >
                                                {{ t('itinerary.changeRoom') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="remove-btn"
                                                :aria-label="
                                                    t('itinerary.remove')
                                                "
                                                @click="
                                                    clearRoom(
                                                        variantIndex,
                                                        dayIndex,
                                                    )
                                                "
                                            >
                                                ×
                                            </button>
                                        </template>
                                        <button
                                            v-else
                                            type="button"
                                            class="room-selection-add-btn"
                                            @click="
                                                toggleRoomPicker(
                                                    variantIndex,
                                                    dayIndex,
                                                )
                                            "
                                        >
                                            🛏️ {{ t('itinerary.chooseRoom') }}
                                        </button>
                                    </div>
                                    <RoomTypePicker
                                        v-if="
                                            roomPickerKey ===
                                            roomSelectionKey(
                                                variantIndex,
                                                dayIndex,
                                            )
                                        "
                                        :base-price="
                                            day.accommodation?.price_from ??
                                            null
                                        "
                                        :currency="
                                            day.accommodation?.price_currency ??
                                            'NAD'
                                        "
                                        :adults="currentTripParams?.adults ?? 2"
                                        :children="
                                            currentTripParams?.children_under_13 ??
                                            0
                                        "
                                        @select="
                                            (option) =>
                                                selectRoom(
                                                    variantIndex,
                                                    dayIndex,
                                                    option,
                                                )
                                        "
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
                        trip_summary: currentTripSummary,
                        variants: [variant],
                        start_location: routeStart,
                        end_location: routeEnd,
                        trip_params: currentTripParams,
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

        <TripParamsEditModal
            v-if="paramsModalOpen"
            :trip-params="currentTripParams"
            :start-location="routeStart"
            :end-location="routeEnd"
            :reference-start-date="startDates[0] ?? null"
            :location-suggestions="startEndLocationSuggestions"
            :saving="regenerating"
            :error="regenerateError"
            @close="paramsModalOpen = false"
            @save="applyParamsEdit"
        />
    </section>
</template>
