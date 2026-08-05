<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import type { ComponentPublicInstance } from 'vue';
import { useI18n } from 'vue-i18n';
import draggable from 'vuedraggable';
import { formatPrice } from '@/lib/currency';
import {
    fetchAllCities,
    fetchAlternatives,
    fetchCities,
    fetchRegionCoords,
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
import ConfirmModal from './ConfirmModal.vue';
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
const dbCities = ref<string[]>([]);
// Unfiltered city list for Startort/Zielort — see fetchAllCities().
const dbAllCities = ref<string[]>([]);
const regionCoords = ref<Record<string, RegionCoords>>({});
const savedTokens = ref<Record<number, string>>({});
const drivingLegsPerVariant = ref<Record<number, DrivingLeg[]>>({});

// Explicit, JS-measured height for the sticky map column (desktop two-col
// layout — see .itinerary-layout in kaia-home.css), keyed by variantIndex.
// CSS align-items: stretch would do this declaratively, but Chromium has a
// reproducible bug where position: sticky never engages on a grid item
// whose height comes from stretch specifically — giving the item an
// explicit pixel height instead (updated live as day rows are
// added/removed/loaded) sidesteps that entirely.
const daysColHeights = ref<Record<number, number>>({});
const daysColObservers: Record<number, ResizeObserver> = {};

function setDaysColRef(
    variantIndex: number,
    el: Element | ComponentPublicInstance | null,
) {
    daysColObservers[variantIndex]?.disconnect();
    delete daysColObservers[variantIndex];

    if (!el || !(el instanceof Element)) {
        return;
    }

    const observer = new ResizeObserver((entries) => {
        daysColHeights.value[variantIndex] = entries[0].contentRect.height;
    });
    observer.observe(el);
    daysColObservers[variantIndex] = observer;
}

onBeforeUnmount(() => {
    Object.values(daysColObservers).forEach((o) => o.disconnect());
});

// Traveler-editable "approx. departure" per drive-time row, keyed like
// roomPickerKey (`${variantIndex}-${dayIndex}` of the arrival day) — purely
// local UI state, not persisted with the plan. Arrival time is derived from
// it plus the OSRM leg duration rather than stored.
const departureTimes = ref<Record<string, string>>({});
const DEFAULT_DEPARTURE_TIME = '08:00';

// Long AI-generated summaries force a lot of scrolling before the plan
// itself is visible — collapse to a couple of lines behind a toggle.
const summaryExpanded = ref(false);
const SUMMARY_TRUNCATE_LENGTH = 220;

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

// --- Confirm dialog for all "×" removal actions — a real modal, not
// window.confirm(), so it matches the rest of the UI. A single pending
// action is enough since only one removal can be triggered at a time.
const confirmDialog = ref<{ message: string; onConfirm: () => void } | null>(
    null,
);

function confirmAndRun(message: string, action: () => void) {
    confirmDialog.value = { message, onConfirm: action };
}

function resolveConfirm() {
    confirmDialog.value?.onConfirm();
    confirmDialog.value = null;
}

function cancelConfirm() {
    confirmDialog.value = null;
}

// Local, editable shadows of the plan-level (not per-variant) fields —
// same reasoning as editableVariants above: regenerating the plan from the
// params-edit popup replaces these without touching the read-only prop.
const currentTripSummary = ref(props.plan.trip_summary);
const currentTripParams = ref<TripParams | null | undefined>(
    props.plan.trip_params,
);
const summaryIsLong = computed(
    () => (currentTripSummary.value?.length ?? 0) > SUMMARY_TRUNCATE_LENGTH,
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
        summaryExpanded.value = false;
        newPlan.variants.forEach((_, i) => applyDates(i));
        swap.value = null;
        roomPickerKey.value = null;
        savedTokens.value = {};
        drivingLegsPerVariant.value = {};
        departureTimes.value = {};
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

function drivingLegSeconds(
    variantIndex: number,
    fromLocation: string | null | undefined,
    toLocation: string | null | undefined,
): number | null {
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

    return leg ? leg.seconds : null;
}

function driveLegKey(variantIndex: number, dayIndex: number): string {
    return `${variantIndex}-${dayIndex}`;
}

function departureTime(variantIndex: number, dayIndex: number): string {
    return (
        departureTimes.value[driveLegKey(variantIndex, dayIndex)] ??
        DEFAULT_DEPARTURE_TIME
    );
}

function setDepartureTime(
    variantIndex: number,
    dayIndex: number,
    value: string,
) {
    if (!value) {
        return;
    }

    departureTimes.value[driveLegKey(variantIndex, dayIndex)] = value;
}

// Approximate arrival — departure plus the OSRM leg duration, wrapped to a
// 24h clock. Purely illustrative (no real scheduled departure exists yet),
// which is why it's rendered with a "ca." / "≈" prefix in the template.
function arrivalTime(
    variantIndex: number,
    dayIndex: number,
    fromLocation: string | null | undefined,
    toLocation: string | null | undefined,
): string | null {
    const seconds = drivingLegSeconds(variantIndex, fromLocation, toLocation);

    if (seconds === null) {
        return null;
    }

    const [h, m] = departureTime(variantIndex, dayIndex).split(':').map(Number);
    const totalMinutes = h * 60 + m + Math.round(seconds / 60);
    const wrapped = ((totalMinutes % 1440) + 1440) % 1440;

    return (
        `${String(Math.floor(wrapped / 60)).padStart(2, '0')}:` +
        `${String(wrapped % 60).padStart(2, '0')}`
    );
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

// Non-stage-start days (e.g. an activity added on day 2 of a multi-night
// stay) only show the day's own end date, not a from/to range — the stay's
// full check-in/check-out range is already shown once on the stage's first
// day via stageDateRangeLabel().
function dayEndDateLabel(day: {
    date?: string | null;
    date_to?: string | null;
}): string {
    return day.date_to || day.date || '';
}

// Accommodation photo first (what the traveler is actually booking), falling
// back to a representative photo for the day's location — regionCoords is
// keyed by destination name, city name, and (for older saved plans from
// before day-locations were city-granularity) the plain political region
// name too.
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

// `day.location` is now always a city (see the AI contract in
// ItineraryService); older saved plans predating that change may still carry
// a political region here instead. Prefer the actual city of the day's
// accommodation for display either way; falls back to `location` itself.
function dayCity(day: {
    location: string;
    accommodation?: { city?: string | null } | null;
}): string {
    return day.accommodation?.city || day.location;
}

// The political region (e.g. "Khomas"), shown as a subtitle next to the city
// name on the timeline card — only known once an accommodation is attached,
// since `day.location` alone doesn't carry it.
function dayRegion(day: {
    accommodation?: { region?: string | null } | null;
}): string | null {
    return day.accommodation?.region || null;
}

// Sums the stage-start day's own accommodation/activity/restaurant prices
// for the price badge on its timeline card — mirrors estimatedLabel()'s
// per-day additive logic (price_from is a per-night rate) but for one day
// rather than the whole trip.
function dayItemsPriceLabel(variantIndex: number, dayIndex: number): string | null {
    const day = editableVariants.value[variantIndex].days[dayIndex];
    let amount = 0;
    let hasAnyPrice = false;

    for (const item of [day.accommodation, day.activity, day.restaurant]) {
        if (item?.price_from) {
            amount += Number(item.price_from);
            hasAnyPrice = true;
        }
    }

    if (!hasAnyPrice) {
        return null;
    }

    const travelers =
        (currentTripParams.value?.adults ?? 0) +
        (currentTripParams.value?.children_under_13 ?? 0);
    const price = formatPrice(String(amount));

    return travelers > 0
        ? `${price} ${t('itinerary.forTravelers', { count: travelers })}`
        : price;
}

// Identifies a day's accommodation for stay-grouping purposes — null (no
// accommodation) never matches anything, including another blank day, so a
// gap never gets accidentally merged into a "stay".
function stayIdentity(day: {
    accommodation?: {
        id: number | null;
        slug: string | null;
        name: string;
    } | null;
}): string | null {
    const acc = day.accommodation;

    if (!acc) {
        return null;
    }

    return String(acc.id ?? acc.slug ?? acc.name);
}

// A day starts a new "stage" (Etappe) when it's the first day, or its
// accommodation differs from the previous day's — consecutive days at the
// same accommodation are one stage: the city heading, thumbnail, and
// "Unterkunft" line only show once, on the stage's first day.
function isStageStart(variantIndex: number, dayIndex: number): boolean {
    if (dayIndex === 0) {
        return true;
    }

    const days = editableVariants.value[variantIndex].days;

    return stayIdentity(days[dayIndex]) !== stayIdentity(days[dayIndex - 1]);
}

// Last day index (inclusive) of the stay that starts at dayIndex — walks
// forward while the accommodation stays the same.
function stageEndIndex(variantIndex: number, dayIndex: number): number {
    const days = editableVariants.value[variantIndex].days;
    const identity = stayIdentity(days[dayIndex]);

    if (identity === null) {
        return dayIndex;
    }

    let end = dayIndex;

    while (end + 1 < days.length && stayIdentity(days[end + 1]) === identity) {
        end++;
    }

    return end;
}

// A day beyond a stage's first is only worth showing at all once it's
// collapsed down to "is there anything left on it" — its own accommodation
// line/marker/date are already summarized on the stage's first day.
function dayHasActivityOrRestaurant(day: {
    activity?: unknown;
    restaurant?: unknown;
}): boolean {
    return !!day.activity || !!day.restaurant;
}

// The stage's first day shows the whole stay's check-in -> check-out range
// (not just its own single-night range) when it spans more than one day.
function stageDateRangeLabel(variantIndex: number, dayIndex: number): string {
    const days = editableVariants.value[variantIndex].days;
    const endIndex = stageEndIndex(variantIndex, dayIndex);

    if (endIndex === dayIndex) {
        return formatDateRange(days[dayIndex]);
    }

    return formatDateRange({
        date: days[dayIndex].date,
        date_to: days[endIndex].date_to,
    });
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
    const endIndex = stageEndIndex(variantIndex, dayIndex);

    for (let i = dayIndex; i <= endIndex; i++) {
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

// Registered before the immediate `props.plan` watcher below, on purpose:
// that watcher's very first (immediate) run is what populates
// editableVariants/routeStart/routeEnd/currentTripParams for a brand-new
// plan, and this watcher has to already be active to catch that initial
// write — otherwise the first-ever plan silently never gets persisted (no
// token, no ?trip= URL) until the traveler happens to make a manual edit.
watch(
    [editableVariants, routeStart, routeEnd, currentTripParams],
    schedulePersist,
    { deep: true },
);

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
        summaryExpanded.value = false;
        swap.value = null;
        roomPickerKey.value = null;
        departureTimes.value = {};

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
    departureTimes.value = {};
}

onMounted(async () => {
    [regionCoords.value, dbCities.value, dbAllCities.value] = await Promise.all(
        [fetchRegionCoords(), fetchCities(), fetchAllCities()],
    );
});

// Combine DB cities with locations already in the plan — deduplicated. Used
// for per-day location editing, where the underlying value must stay a real
// city (see dayCity()/LocationPicker's `label` prop).
const locationSuggestions = computed(() => {
    const planLocations = editableVariants.value
        .flatMap((v) => v.days.map((d) => d.location))
        .filter(Boolean);

    return [...new Set([...planLocations, ...dbCities.value])].sort();
});

// Real city names (not regions) for the trip-wide Startort/Zielort fields —
// dbAllCities, not dbCities: a start/end point doesn't need a published
// listing of its own (see fetchAllCities()).
const startEndLocationSuggestions = computed(() =>
    [
        ...new Set([routeStart.value, routeEnd.value, ...dbAllCities.value]),
    ].sort(),
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
        editableVariants.value.map((variant, i) => {
            // The common case (a single variant) is already sitting behind
            // currentToken from auto-persist — reuse that instead of
            // minting a second, orphaned token whose /trip/ link would
            // silently diverge from the ?trip= link already in the address
            // bar (same plan content, two different saved rows/tokens).
            if (editableVariants.value.length === 1 && currentToken.value) {
                savedTokens.value[i] = currentToken.value;

                return Promise.resolve();
            }

            return savePlan({
                trip_summary: currentTripSummary.value,
                variants: [variant],
                start_location: routeStart.value,
                end_location: routeEnd.value,
                trip_params: currentTripParams.value,
            }).then((result) => {
                savedTokens.value[i] = result.token;
            });
        }),
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
            <p
                v-if="currentTripSummary"
                class="trip-summary-text"
                :class="{
                    'trip-summary-text--clamped':
                        summaryIsLong && !summaryExpanded,
                }"
            >
                {{ currentTripSummary }}
            </p>
            <button
                v-if="summaryIsLong"
                type="button"
                class="trip-summary-toggle"
                @click="summaryExpanded = !summaryExpanded"
            >
                {{
                    summaryExpanded
                        ? t('itinerary.showLess')
                        : t('itinerary.readMore')
                }}
            </button>
        </div>
        <div class="variants">
            <div
                v-for="(variant, variantIndex) in editableVariants"
                :key="variant.name"
                class="variant-card"
            >
                <div
                    class="variant-head"
                    :class="{ 'variant-head--single': editableVariants.length === 1 }"
                >
                    <a
                        v-if="editableVariants.length === 1"
                        href="/"
                        class="plan-back-btn"
                        :aria-label="t('itinerary.back')"
                        :title="t('itinerary.back')"
                        >←</a
                    >
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
                            v-if="editableVariants.length === 1"
                            type="button"
                            class="plan-edit-btn"
                            @click="openParamsEditor"
                        >
                            ✏️ {{ t('itinerary.editPlan') }}
                        </button>
                        <button
                            v-if="editableVariants.length > 1"
                            type="button"
                            class="dismiss-variant-btn"
                            :aria-label="t('itinerary.dismissPlan')"
                            :title="t('itinerary.dismissPlan')"
                            @click="
                                confirmAndRun(
                                    t('itinerary.confirmRemove.plan'),
                                    () => dismissVariant(variantIndex),
                                )
                            "
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
                    :editable="editableVariants.length > 1"
                    @edit="openParamsEditor"
                />
                <div v-if="regenerating" class="params-regenerating-note">
                    {{ t('itinerary.paramsEditor.saving') }}
                </div>

                <template v-if="variant.vehicle">
                    <div class="vehicle-card">
                        <img
                            v-if="variant.vehicle.image"
                            :src="variant.vehicle.image"
                            :alt="variant.vehicle.name"
                            class="vehicle-card-img"
                        />
                        <ItineraryLineItem
                            keypath="itinerary.vehicle"
                            :item-ref="variant.vehicle"
                            :swap-label="t('itinerary.changeRoom')"
                            class="variant-vehicle"
                            @remove="
                                confirmAndRun(
                                    t('itinerary.confirmRemove.vehicle'),
                                    () => {
                                        variant.vehicle = null;
                                    },
                                )
                            "
                            @swap="
                                openSwap(
                                    variantIndex,
                                    null,
                                    'vehicle',
                                    variant.vehicle!,
                                )
                            "
                        />
                    </div>
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

                <div class="itinerary-layout">
                    <div
                        class="itinerary-map-col"
                        :style="
                            daysColHeights[variantIndex]
                                ? {
                                      height: `${daysColHeights[variantIndex]}px`,
                                  }
                                : undefined
                        "
                    >
                        <div class="itinerary-map-sticky">
                            <TripMap
                                :map-id="`trip-map-${variantIndex}`"
                                :variant="variant"
                                :region-coords="regionCoords"
                                @driving-legs="
                                    (legs) => {
                                        drivingLegsPerVariant[variantIndex] =
                                            legs;
                                    }
                                "
                            />
                        </div>
                    </div>
                    <div
                        class="itinerary-days-col"
                        :ref="(el) => setDaysColRef(variantIndex, el)"
                    >
                        <button
                            type="button"
                            class="add-day-btn"
                            @click="addDay(variantIndex, -1)"
                        >
                            + {{ t('itinerary.addDay') }}
                        </button>

                        <div class="itinerary-timeline">
                        <draggable
                            v-model="editableVariants[variantIndex].days"
                            item-key="day"
                            handle=".drag-handle"
                            :animation="150"
                            ghost-class="day-item--ghost"
                            @end="renumberDays(variantIndex)"
                        >
                            <template #item="{ element: day, index: dayIndex }">
                                <div
                                    v-show="
                                        isStageStart(variantIndex, dayIndex) ||
                                        dayHasActivityOrRestaurant(day)
                                    "
                                    class="day-item"
                                >
                                    <div v-if="dayIndex > 0" class="day-connector">
                                        <div class="day-connector-rail"></div>
                                        <div class="day-connector-content">
                                        <div
                                            v-if="
                                                day.location !==
                                                    editableVariants[
                                                        variantIndex
                                                    ].days[dayIndex - 1]
                                                        .location &&
                                                drivingTimeBetween(
                                                    variantIndex,
                                                    editableVariants[
                                                        variantIndex
                                                    ].days[dayIndex - 1]
                                                        .location,
                                                    day.location,
                                                )
                                            "
                                            class="drive-time-row"
                                        >
                                            <svg
                                                class="drive-time-icon"
                                                viewBox="0 0 28 18"
                                                aria-hidden="true"
                                                focusable="false"
                                            >
                                                <rect
                                                    x="1"
                                                    y="8"
                                                    width="24"
                                                    height="5"
                                                    rx="1.2"
                                                />
                                                <rect
                                                    x="6"
                                                    y="3.2"
                                                    width="12"
                                                    height="5.3"
                                                    rx="1"
                                                />
                                                <rect
                                                    x="7.5"
                                                    y="0.8"
                                                    width="1.6"
                                                    height="2.6"
                                                    rx="0.5"
                                                />
                                                <rect
                                                    x="16.4"
                                                    y="0.8"
                                                    width="1.6"
                                                    height="2.6"
                                                    rx="0.5"
                                                />
                                                <circle
                                                    cx="21.5"
                                                    cy="9.6"
                                                    r="2.1"
                                                />
                                                <circle
                                                    cx="6.2"
                                                    cy="15.2"
                                                    r="2.6"
                                                />
                                                <circle
                                                    cx="19.5"
                                                    cy="15.2"
                                                    r="2.6"
                                                />
                                            </svg>
                                            <span class="drive-time-label">
                                                {{
                                                    drivingTimeBetween(
                                                        variantIndex,
                                                        editableVariants[
                                                            variantIndex
                                                        ].days[dayIndex - 1]
                                                            .location,
                                                        day.location,
                                                    )
                                                }}
                                            </span>
                                            <span class="drive-time-schedule">
                                                <label
                                                    class="drive-time-departure"
                                                >
                                                    {{ t('itinerary.departure') }}
                                                    <input
                                                        type="time"
                                                        class="drive-time-input"
                                                        :value="
                                                            departureTime(
                                                                variantIndex,
                                                                dayIndex,
                                                            )
                                                        "
                                                        @input="
                                                            setDepartureTime(
                                                                variantIndex,
                                                                dayIndex,
                                                                (
                                                                    $event.target as HTMLInputElement
                                                                ).value,
                                                            )
                                                        "
                                                    />
                                                </label>
                                                <span
                                                    v-if="
                                                        arrivalTime(
                                                            variantIndex,
                                                            dayIndex,
                                                            editableVariants[
                                                                variantIndex
                                                            ].days[dayIndex - 1]
                                                                .location,
                                                            day.location,
                                                        )
                                                    "
                                                    class="drive-time-arrival"
                                                >
                                                    · {{ t('itinerary.arrival') }}
                                                    ≈
                                                    {{
                                                        arrivalTime(
                                                            variantIndex,
                                                            dayIndex,
                                                            editableVariants[
                                                                variantIndex
                                                            ].days[dayIndex - 1]
                                                                .location,
                                                            day.location,
                                                        )
                                                    }}
                                                </span>
                                            </span>
                                        </div>
                                        <button
                                            type="button"
                                            class="add-day-inline-btn"
                                            :title="t('itinerary.addDay')"
                                            :aria-label="t('itinerary.addDay')"
                                            @click="
                                                addDay(
                                                    variantIndex,
                                                    dayIndex - 1,
                                                )
                                            "
                                        >
                                            +
                                        </button>
                                        </div>
                                    </div>

                                    <div class="day-content">
                                        <div class="day-rail">
                                            <template
                                                v-if="
                                                    isStageStart(
                                                        variantIndex,
                                                        dayIndex,
                                                    )
                                                "
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
                                            </template>
                                        </div>

                                        <div
                                            class="day-card"
                                            :class="{
                                                'day-card--continuation':
                                                    !isStageStart(
                                                        variantIndex,
                                                        dayIndex,
                                                    ),
                                            }"
                                        >
                                            <template
                                                v-if="
                                                    isStageStart(
                                                        variantIndex,
                                                        dayIndex,
                                                    )
                                                "
                                            >
                                                <div class="day-card-header">
                                                    <span
                                                        class="drag-handle"
                                                        :title="
                                                            t(
                                                                'itinerary.dragToReorder',
                                                            )
                                                        "
                                                        >⠿</span
                                                    >
                                                    <img
                                                        v-if="dayThumbnail(day)"
                                                        :src="dayThumbnail(day)!"
                                                        alt=""
                                                        class="day-thumb"
                                                    />
                                                    <div class="day-card-title">
                                                        <LocationPicker
                                                            :model-value="
                                                                day.location
                                                            "
                                                            :label="dayCity(day)"
                                                            :suggestions="
                                                                locationSuggestions
                                                            "
                                                            @update:model-value="
                                                                setStageLocation(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                    $event,
                                                                )
                                                            "
                                                        />
                                                        <span
                                                            v-if="dayRegion(day)"
                                                            class="day-card-region"
                                                            >{{
                                                                dayRegion(day)
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="day-card-header-right"
                                                    >
                                                        <span
                                                            v-if="
                                                                dayItemsPriceLabel(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                )
                                                            "
                                                            class="day-card-price"
                                                            >{{
                                                                dayItemsPriceLabel(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                )
                                                            }}</span
                                                        >
                                                        <button
                                                            type="button"
                                                            class="remove-btn"
                                                            :aria-label="
                                                                t(
                                                                    'itinerary.removeDay',
                                                                )
                                                            "
                                                            @click="
                                                                confirmAndRun(
                                                                    t(
                                                                        'itinerary.confirmRemove.day',
                                                                    ),
                                                                    () =>
                                                                        removeDay(
                                                                            variantIndex,
                                                                            dayIndex,
                                                                        ),
                                                                )
                                                            "
                                                        >
                                                            ×
                                                        </button>
                                                    </div>
                                                </div>
                                                <div
                                                    v-if="day.date"
                                                    class="day-card-sub"
                                                >
                                                    {{
                                                        stageDateRangeLabel(
                                                            variantIndex,
                                                            dayIndex,
                                                        )
                                                    }}
                                                </div>

                                                <div class="day-card-grid">
                                                    <div class="day-card-box">
                                                        <div
                                                            class="day-card-box-label"
                                                        >
                                                            {{
                                                                t(
                                                                    'itinerary.stayLabel',
                                                                )
                                                            }}
                                                        </div>
                                                        <ItineraryLineItem
                                                            hide-label
                                                            keypath="itinerary.stay"
                                                            :item-ref="
                                                                day.accommodation
                                                            "
                                                            @remove="
                                                                confirmAndRun(
                                                                    t(
                                                                        'itinerary.confirmRemove.item',
                                                                    ),
                                                                    () =>
                                                                        removeItem(
                                                                            variantIndex,
                                                                            dayIndex,
                                                                            'accommodation',
                                                                        ),
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
                                                        <div
                                                            v-if="
                                                                day.accommodation
                                                            "
                                                            class="room-selection-row"
                                                        >
                                                            <template
                                                                v-if="
                                                                    day.room_selection
                                                                "
                                                            >
                                                                <span
                                                                    class="room-selection-chip"
                                                                >
                                                                    🛏️
                                                                    {{
                                                                        day
                                                                            .room_selection
                                                                            .name
                                                                    }}
                                                                    ·
                                                                    {{
                                                                        formatPrice(
                                                                            String(
                                                                                day
                                                                                    .room_selection
                                                                                    .price_per_night,
                                                                            ),
                                                                        )
                                                                    }}/{{
                                                                        t(
                                                                            'itinerary.perNight',
                                                                        )
                                                                    }}
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
                                                                    {{
                                                                        t(
                                                                            'itinerary.changeRoom',
                                                                        )
                                                                    }}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    class="remove-btn"
                                                                    :aria-label="
                                                                        t(
                                                                            'itinerary.remove',
                                                                        )
                                                                    "
                                                                    @click="
                                                                        confirmAndRun(
                                                                            t(
                                                                                'itinerary.confirmRemove.room',
                                                                            ),
                                                                            () =>
                                                                                clearRoom(
                                                                                    variantIndex,
                                                                                    dayIndex,
                                                                                ),
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
                                                                🛏️
                                                                {{
                                                                    t(
                                                                        'itinerary.chooseRoom',
                                                                    )
                                                                }}
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <div class="day-card-box">
                                                        <div
                                                            class="day-card-box-label"
                                                        >
                                                            {{
                                                                t(
                                                                    'itinerary.activityLabel',
                                                                )
                                                            }}
                                                        </div>
                                                        <ItineraryLineItem
                                                            hide-label
                                                            keypath="itinerary.activity"
                                                            :item-ref="
                                                                day.activity
                                                            "
                                                            @remove="
                                                                confirmAndRun(
                                                                    t(
                                                                        'itinerary.confirmRemove.item',
                                                                    ),
                                                                    () =>
                                                                        removeItem(
                                                                            variantIndex,
                                                                            dayIndex,
                                                                            'activity',
                                                                        ),
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
                                                    </div>

                                                    <div class="day-card-box">
                                                        <div
                                                            class="day-card-box-label"
                                                        >
                                                            {{
                                                                t(
                                                                    'itinerary.dinnerLabel',
                                                                )
                                                            }}
                                                        </div>
                                                        <ItineraryLineItem
                                                            hide-label
                                                            keypath="itinerary.dinner"
                                                            :item-ref="
                                                                day.restaurant
                                                            "
                                                            @remove="
                                                                confirmAndRun(
                                                                    t(
                                                                        'itinerary.confirmRemove.item',
                                                                    ),
                                                                    () =>
                                                                        removeItem(
                                                                            variantIndex,
                                                                            dayIndex,
                                                                            'restaurant',
                                                                        ),
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
                                                    </div>
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
                                                        day.accommodation
                                                            ?.price_from ?? null
                                                    "
                                                    :currency="
                                                        day.accommodation
                                                            ?.price_currency ??
                                                        'NAD'
                                                    "
                                                    :adults="
                                                        currentTripParams?.adults ??
                                                        2
                                                    "
                                                    :children="
                                                        currentTripParams?.children_under_13 ??
                                                        0
                                                    "
                                                    :images="
                                                        day.accommodation
                                                            ?.gallery?.length
                                                            ? day.accommodation
                                                                  .gallery
                                                            : day.accommodation
                                                                    ?.image
                                                              ? [
                                                                    day
                                                                        .accommodation
                                                                        .image,
                                                                ]
                                                              : []
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
                                            </template>

                                            <template v-else>
                                                <div class="day-card-header">
                                                    <span class="day-card-sub">{{
                                                        dayEndDateLabel(day)
                                                    }}</span>
                                                    <button
                                                        type="button"
                                                        class="remove-btn"
                                                        :aria-label="
                                                            t(
                                                                'itinerary.removeDay',
                                                            )
                                                        "
                                                        @click="
                                                            confirmAndRun(
                                                                t(
                                                                    'itinerary.confirmRemove.day',
                                                                ),
                                                                () =>
                                                                    removeDay(
                                                                        variantIndex,
                                                                        dayIndex,
                                                                    ),
                                                            )
                                                        "
                                                    >
                                                        ×
                                                    </button>
                                                </div>
                                                <div class="day-card-grid">
                                                    <div class="day-card-box">
                                                        <div
                                                            class="day-card-box-label"
                                                        >
                                                            {{
                                                                t(
                                                                    'itinerary.activityLabel',
                                                                )
                                                            }}
                                                        </div>
                                                        <ItineraryLineItem
                                                            hide-label
                                                            keypath="itinerary.activity"
                                                            :item-ref="
                                                                day.activity
                                                            "
                                                            @remove="
                                                                confirmAndRun(
                                                                    t(
                                                                        'itinerary.confirmRemove.item',
                                                                    ),
                                                                    () =>
                                                                        removeItem(
                                                                            variantIndex,
                                                                            dayIndex,
                                                                            'activity',
                                                                        ),
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
                                                    </div>
                                                    <div class="day-card-box">
                                                        <div
                                                            class="day-card-box-label"
                                                        >
                                                            {{
                                                                t(
                                                                    'itinerary.dinnerLabel',
                                                                )
                                                            }}
                                                        </div>
                                                        <ItineraryLineItem
                                                            hide-label
                                                            keypath="itinerary.dinner"
                                                            :item-ref="
                                                                day.restaurant
                                                            "
                                                            @remove="
                                                                confirmAndRun(
                                                                    t(
                                                                        'itinerary.confirmRemove.item',
                                                                    ),
                                                                    () =>
                                                                        removeItem(
                                                                            variantIndex,
                                                                            dayIndex,
                                                                            'restaurant',
                                                                        ),
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
                                                    </div>
                                                </div>
                                            </template>

                                            <AlternativesPanel
                                                v-if="
                                                    swap &&
                                                    swap.variantIndex ===
                                                        variantIndex &&
                                                    swap.dayIndex === dayIndex
                                                "
                                                :loading="swap.loading"
                                                :alternatives="
                                                    swap.alternatives
                                                "
                                                @select="applySwap"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </draggable>
                        </div>

                        <button
                            type="button"
                            class="add-day-btn"
                            @click="
                                addDay(variantIndex, variant.days.length - 1)
                            "
                        >
                            + {{ t('itinerary.addDay') }}
                        </button>
                    </div>
                </div>

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
                    :existing-token="
                        editableVariants.length === 1 ? currentToken : null
                    "
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

        <ConfirmModal
            v-if="confirmDialog"
            :message="confirmDialog.message"
            @confirm="resolveConfirm"
            @cancel="cancelConfirm"
        />
    </section>
</template>
