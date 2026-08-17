<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { GripHorizontal } from '@lucide/vue';
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import type { ComponentPublicInstance } from 'vue';
import { useI18n } from 'vue-i18n';
import draggable from 'vuedraggable';
import ImageLightbox from '@/components/ImageLightbox.vue';
import { formatPrice } from '@/lib/currency';
import {
    claimPlan,
    fetchAllCities,
    fetchCities,
    fetchRegionCoords,
    PlanConflictError,
    regeneratePlan,
    savePlan,
    updatePlan,
} from '@/lib/kaia-client';
import type { RegionCoords } from '@/lib/kaia-client';
import type {
    DayEntry,
    ItineraryDay,
    ItineraryListingRef,
    ItineraryPlan,
    ItineraryVariant,
    RoomOption,
    TripParams,
} from '@/lib/kaia-types';
import { vehicleTypeForClass } from '@/lib/kaia-types';
import { onImageError, thumbAttrs } from '@/lib/media';
import { isRecurring, travelerFactor } from '@/lib/price-unit';
import AvailabilityPicker from './AvailabilityPicker.vue';
import ConfirmModal from './ConfirmModal.vue';
import ItineraryDayPlanCard from './ItineraryDayPlanCard.vue';
import ItineraryLineItem from './ItineraryLineItem.vue';
import ItineraryStayCard from './ItineraryStayCard.vue';
import KebabMenu from './KebabMenu.vue';
import ListingSwapModal from './ListingSwapModal.vue';
import LocationPicker from './LocationPicker.vue';
import MapViewModal from './MapViewModal.vue';
import SaveButton from './SaveButton.vue';
import SaveLoginModal from './SaveLoginModal.vue';
import SaveShareBar from './SaveShareBar.vue';
import ShareButton from './ShareButton.vue';
import TripMap from './TripMap.vue';
import type { DrivingLeg } from './TripMap.vue';
import TripMeta from './TripMeta.vue';
import TripParamsEditModal from './TripParamsEditModal.vue';
import type { TripParamsFormValues } from './TripParamsEditModal.vue';

const props = defineProps<{
    plan: ItineraryPlan;
    token: string | null;
    // The plan's server version when it was loaded, for the stale-write check
    // in KaiaController::updatePlan. Null for a freshly generated plan that
    // hasn't been persisted yet — the first autosave mints it.
    version?: number | null;
    // The plan's read-only token — what the share affordances hand out. Never
    // `token`, which grants editing.
    shareToken?: string | null;
    // False when this plan was opened through a read-only share link. The
    // server rejects writes regardless; this stops the UI from offering edits
    // it knows will fail, and stops the autosave from firing at all.
    canEdit?: boolean;
    // Whether the plan already sits in this traveler's account. Separate from
    // having a token: the autosave persists anonymously, and only an
    // authenticated claim (KaiaController::claimPlan) makes a plan owned.
    owned?: boolean;
    // ItineraryItem rows created when booking requests were sent. Used to show
    // booking status badges on the relevant accommodation entries.
    bookings?: Array<{
        listing_id: number | null;
        kind: string;
        date: string | null;
        date_to: string | null;
        inquiry_status: string | null;
    }>;
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
const unitPickerKey = ref<string | null>(null);
// Which day rows on the timeline are folded shut, keyed variantIndex-dayIndex.
// Purely a viewing convenience — never persisted with the plan, and reset
// whenever days are added, removed or reordered, since the index a key points
// at stops meaning the same day the moment the list shifts under it.
const collapsedDays = ref<Record<string, boolean>>({});
// The inline map is hidden on mobile for the single-variant page (see
// .itinerary-map-col in kaia-home.css) — this opens it in a full modal
// instead, triggered by the floating map button.
const mapModalOpen = ref(false);
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

// Moves with every successful save. Sent back on the next one so the server
// can reject a write made against a plan that has since changed elsewhere —
// the share link is the same token, so "elsewhere" is a real scenario, as is
// simply having the plan open in two tabs.
const currentVersion = ref<number | null>(props.version ?? null);

const currentShareToken = ref<string | null>(props.shareToken ?? null);

// Moves to true the moment the plan is attached to an account — either because
// the traveler was already logged in when it was first persisted, or because
// they pressed Save (which claims it). Drives the Save button's state.
const currentOwned = ref<boolean>(props.owned ?? false);

// Everything editable in this section is gated on this. Defaults to editable
// so the ordinary chat -> plan flow (which passes no canEdit at all) is
// unaffected; only a read-only share link turns it off.
const readonly = computed(() => props.canEdit === false);

// Keyed lookup of booking status per accommodation listing, seeded from the
// ItineraryItem rows created when booking requests were sent. The badge on the
// stay card reads from here so it stays live without re-fetching the plan.
const bookingStatusByListingId = computed(() => {
    const map = new Map<number, string>();

    for (const booking of props.bookings ?? []) {
        if (booking.listing_id !== null && booking.inquiry_status !== null) {
            map.set(booking.listing_id, booking.inquiry_status);
        }
    }

    return map;
});

// Set once the server has rejected a write as stale. Autosaving stops at that
// point: the local plan and the stored one have diverged, and continuing to
// retry would either spam 409s or, worse, overwrite whatever the other editor
// did. The traveler is told, and reloading is the way out.
const planConflict = ref(false);

// Deliberately a full page reload rather than re-fetching into the existing
// state: the unsaved local edits are the losing side of the conflict, and
// quietly swapping the plan out from under them mid-edit would be worse than
// an obvious reload the traveler chose.
function reloadPlan() {
    window.location.reload();
}

// Trip-level start/end — same for every variant. Reversing a variant's
// direction (below) doesn't touch these; it only reorders that variant's
// own days. Edited together with the rest of the trip params via the
// params-edit popup (see openParamsEditor/applyParamsEdit below).
const routeStart = ref('Windhoek');
const routeEnd = ref('Windhoek');

// --- Auth-gate for saving and booking ---
const showAuthModal = ref(false);
// What the modal was opened for, so logging in continues the thing the
// traveler actually pressed instead of always falling through to "save".
const authIntent = ref<'save' | 'book'>('save');
const pendingBookVariant = ref<ItineraryVariant | null>(null);

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
            // The class the traveler picked decides the legacy binary, so the
            // two can't contradict each other; without one, the plan keeps
            // whatever the interview established.
            vehicle_type: values.vehicleClass
                ? vehicleTypeForClass(values.vehicleClass)
                : currentTripParams.value?.vehicle_type || 'car',
            vehicle_class: values.vehicleClass,
            vehicle_daily_budget: values.vehicleDailyBudget,
            start_location: values.startLocation,
            end_location: values.endLocation,
        });

        editableVariants.value = normalizeVariants(
            JSON.parse(JSON.stringify(newPlan.variants)),
        );
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

// Kaia (and older saved plans) still carry a single `activity`/`restaurant`
// field per day — the traveler-added "2nd/3rd activity" feature needs an
// array instead. Normalize once, right where a plan enters editableVariants,
// so every function/template downstream can assume day.activities/
// day.restaurants always exist and never has to look at the singular fields.
function normalizeDay(day: ItineraryDay): ItineraryDay {
    const { activity, restaurant, ...rest } = day;

    return {
        ...rest,
        activities: day.activities ?? (activity ? [activity] : []),
        restaurants: day.restaurants ?? (restaurant ? [restaurant] : []),
        departure_activities: day.departure_activities ?? [],
        departure_restaurants: day.departure_restaurants ?? [],
    };
}

function normalizeVariants(variants: ItineraryVariant[]): ItineraryVariant[] {
    return variants.map((variant) => ({
        ...variant,
        days: variant.days.map(normalizeDay),
    }));
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

// A single day's own date, as stored ("15 Aug 2026"). The stay's full
// check-in/check-out range is shown once, separately, on the accommodation
// card via stageDateRangeLabel(); this is the per-day date the day card's
// header shortens for display — see dayRailDate().
function dayDateLabel(day: {
    date?: string | null;
    date_to?: string | null;
}): string {
    return day.date || day.date_to || '';
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

// Every photo worth showing for a stage, for the thumbnail's lightbox: the
// destination's own representative shot first, then the stay's hero + gallery.
// NOTE: `destinations`/`cities` each hold exactly ONE image column — there is
// no per-city gallery in the DB — so a stage with a photo-less accommodation
// legitimately yields a single image, and ImageLightbox just hides its arrows.
function stageImages(day: {
    location: string;
    accommodation?: { image?: string | null; gallery?: string[] } | null;
}): string[] {
    const key = day.location?.toLowerCase().trim();

    return [
        ...((key && regionCoords.value[key]?.image
            ? [regionCoords.value[key].image]
            : []) as string[]),
        ...(day.accommodation?.image ? [day.accommodation.image] : []),
        ...(day.accommodation?.gallery ?? []),
    ].filter((src, index, all) => all.indexOf(src) === index);
}

// Which stage's photos the lightbox is showing, and how far into them — null
// when closed. Keyed by variant+day so two stages can't fight over it.
const lightbox = ref<{ key: string; images: string[]; index: number } | null>(
    null,
);

function openStageLightbox(
    variantIndex: number,
    dayIndex: number,
    day: Parameters<typeof stageImages>[0],
) {
    const images = stageImages(day);

    if (images.length === 0) {
        return;
    }

    lightbox.value = { key: `${variantIndex}-${dayIndex}`, images, index: 0 };
}

// A stage is headed by its city, never by its political region — see
// ItineraryService::normalizeDayLocations, which resolves `day.location` to
// a real city server-side (preferring the accommodation's own city already).
// `location` is what every *other* part of the stage reads — the map pin,
// the driving legs, the thumbnail, the swap modal's "same city" default, and
// the LocationPicker this heading doubles as — so heading it with anything
// else makes clicking the city open a picker on a different town. The
// accommodation's city is only a fallback for a day that carries no location
// of its own.
function dayCity(day: {
    location: string;
    accommodation?: { city?: string | null } | null;
}): string {
    return day.location || day.accommodation?.city || '';
}

function sameCity(a?: string | null, b?: string | null): boolean {
    return !!a && !!b && a.toLowerCase().trim() === b.toLowerCase().trim();
}

// The political region (e.g. "Khomas"), shown quieter beside the city name on
// the timeline card. Three sources in decreasing precision: the region the
// backend resolved for the day, the stay's own region, and finally a lookup
// by city name in the coords index — that last one is what covers a stay
// whose listing has no city_id (plenty of scraped ones don't) and older saved
// plans that predate `day.region`.
function dayRegion(day: {
    location: string;
    region?: string | null;
    accommodation?: { city?: string | null; region?: string | null } | null;
}): string | null {
    const lookUp = (name?: string | null) =>
        (name && regionCoords.value[name.toLowerCase().trim()]?.region) || null;

    const city = dayCity(day);

    // Whatever region we print has to belong to the city in the heading — the
    // stay's own region only counts while the stay is still in that city.
    const region =
        (sameCity(city, day.location) ? day.region : null) ||
        (sameCity(city, day.accommodation?.city)
            ? day.accommodation?.region
            : null) ||
        lookUp(city) ||
        null;

    // Repeating the heading in smaller type says nothing — happens when the
    // heading itself fell back to a region name for want of a city.
    return region && region !== city ? region : null;
}

// How many people the prices apply to. Only ever used to multiply a rate the
// listing itself declares as per-person — a party of four in one room does not
// pay four times a per-room rate, and a plan that assumed otherwise would be
// wrong in the expensive direction.
const travelerCount = computed(() =>
    Math.max(
        1,
        (currentTripParams.value?.adults ?? 2) +
            (currentTripParams.value?.children_under_13 ?? 0),
    ),
);

// What a single plan entry costs this party — its rate times the number of
// travelers it is charged for. Null when the listing has no rate at all, which
// the callers count as "nothing to add" rather than as zero.
function itemCost(item: ItineraryListingRef | null | undefined): number | null {
    if (!item?.price_from) {
        return null;
    }

    return (
        Number(item.price_from) *
        travelerFactor(item.price_unit, travelerCount.value)
    );
}

// Sums a whole stage — its stay for every night it covers, plus every
// activity and restaurant on every one of its days — for the price badge on
// the stage heading. Mirrors estimatedLabel()'s per-day additive logic
// (a stay's rate is charged again for each night) but bounded to one stage
// rather than the whole trip. Deliberately not just the first day's items: the
// heading now spans the entire stage, so a badge counting one night would
// contradict the date range sitting right next to it.
function stageTotal(
    variantIndex: number,
    dayIndex: number,
): { amount: number; hasStay: boolean; stayPriced: boolean } | null {
    const days = editableVariants.value[variantIndex].days;
    const endIndex = stageEndIndex(variantIndex, dayIndex);
    let amount = 0;
    let hasAnyPrice = false;

    for (let i = dayIndex; i <= endIndex; i++) {
        const day = days[i];

        const items = [
            day.accommodation,
            ...(day.activities ?? []),
            ...(day.restaurants ?? []),
            // The checkout morning belongs to this stage — its entries count
            // here, same as the departure row renders under this heading.
            ...(day.departure_activities ?? []),
            ...(day.departure_restaurants ?? []),
        ];

        for (const item of items) {
            const cost = itemCost(item);

            if (cost !== null) {
                amount += cost;
                hasAnyPrice = true;
            }
        }
    }

    return hasAnyPrice
        ? {
              amount,
              hasStay: Boolean(days[dayIndex].accommodation),
              stayPriced: Boolean(days[dayIndex].accommodation?.price_from),
          }
        : null;
}

// Top line of the stage price badge: what the whole stage costs, worded the
// same as the trip and vehicle totals ("~€ 348 geschätzt") so the three read
// as one family. It used to print a bare sum suffixed with the traveler count
// ("€ 17 für 2"), which claimed something the data can't back — `price_from`
// carries no per-person dimension, nothing here is multiplied by party size —
// and left the sum's period unstated next to a three-night date range.
function stagePriceLabel(
    variantIndex: number,
    dayIndex: number,
): string | null {
    const total = stageTotal(variantIndex, dayIndex);

    return total === null
        ? null
        : t('itinerary.estimated', { price: formatPrice(total.amount) });
}

// Second line of the badge, answering "and what is that per night?".
//
// Only when the stay itself is priced: with the stay on request, the sum is
// activities and meals alone, and a per-night figure derived from it reads as
// a room rate — the exact misreading this badge is meant to end. Then it says
// what's missing instead. A single-night stage needs neither: the per-night
// figure would just restate the total.
function stagePriceSubLabel(
    variantIndex: number,
    dayIndex: number,
): string | null {
    const total = stageTotal(variantIndex, dayIndex);

    if (total === null) {
        return null;
    }

    if (!total.stayPriced) {
        return total.hasStay ? t('itinerary.estimateExcludesStay') : null;
    }

    const nights = stageNights(variantIndex, dayIndex);

    return nights > 1
        ? t('itinerary.estimatedPerNight', {
              price: formatPrice(total.amount / nights),
          })
        : null;
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
//
// A day with no accommodation always starts its own stage, matching
// stageEndIndex() below (a null identity never groups). Without that, two
// blank days in a row would merge into one stage and the second one would
// never offer a way to pick a stay.
function isStageStart(variantIndex: number, dayIndex: number): boolean {
    if (dayIndex === 0) {
        return true;
    }

    const days = editableVariants.value[variantIndex].days;
    const identity = stayIdentity(days[dayIndex]);

    return identity === null || identity !== stayIdentity(days[dayIndex - 1]);
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

// The stage's stay as ISO dates, for anything that has to ask the server about
// it — currently the room picker's availability lookup. Null when the plan has
// no dates yet: availability is a property of a date range, so there is nothing
// to ask without one.
//
// Check-out is the day after the stage's last night. `date_to` already means
// that where Kaia filled it in; otherwise it's derived, rather than sending a
// zero-night range the server would reject.
function stageDateRangeIso(
    variantIndex: number,
    dayIndex: number,
): { checkIn: string; checkOut: string } | null {
    const days = editableVariants.value[variantIndex].days;
    const endIndex = stageEndIndex(variantIndex, dayIndex);

    const checkIn = parseDayDate(days[dayIndex].date);

    if (!checkIn) {
        return null;
    }

    let checkOut = parseDayDate(days[endIndex].date_to);

    if (!checkOut || checkOut <= checkIn) {
        const lastNight = parseDayDate(days[endIndex].date) ?? checkIn;
        checkOut = new Date(lastNight);
        checkOut.setDate(checkOut.getDate() + 1);
    }

    return { checkIn: toIsoDate(checkIn), checkOut: toIsoDate(checkOut) };
}

// Local calendar date, not UTC: toISOString() would shift a date back a day
// for anyone east of Greenwich, which is every traveler in Namibia.
function toIsoDate(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

// How many nights the stage's stay covers — one per day in the run, shown
// next to the date range on the stay card. Zero when the plan has no dates
// yet, which is the card's cue to leave the count off entirely.
function stageNights(variantIndex: number, dayIndex: number): number {
    if (!editableVariants.value[variantIndex].days[dayIndex].date) {
        return 0;
    }

    return stageEndIndex(variantIndex, dayIndex) - dayIndex + 1;
}

// The stage heading's second line: the stay's full range plus how many nights
// it covers ("1 – 4 Jan 2027 (3 Nächte)"). The nights count drops out until
// the plan has dates, which is also when the range itself is empty.
function stageDateSubLabel(variantIndex: number, dayIndex: number): string {
    const range = stageDateRangeLabel(variantIndex, dayIndex);
    const nights = stageNights(variantIndex, dayIndex);

    if (!range || nights <= 0) {
        return range;
    }

    return `${range} (${t('itinerary.meta.nights', nights)})`;
}

// Last day of its own stage — the day the traveler drives on to the next
// place, which is why the drive-time connector hangs directly below it.
function isStageEnd(variantIndex: number, dayIndex: number): boolean {
    const days = editableVariants.value[variantIndex].days;

    return (
        dayIndex === days.length - 1 || isStageStart(variantIndex, dayIndex + 1)
    );
}

// "Ankunft" beside a day's date in its card header. Only the arrival day
// carries a label here — departure is not one of the stage's nights but the morning
// after the last one, so it gets a row of its own (see the departure-day row
// in the template) instead of mislabelling the last night as the departure.
function dayRailLabel(variantIndex: number, dayIndex: number): string | null {
    return isStageStart(variantIndex, dayIndex)
        ? t('itinerary.dayArrival')
        : null;
}

// Whether the stage ending at dayIndex gets a departure-day row: the checkout
// morning is only worth a row of its own once there is an actual stay to
// check out of. A freshly added, still-empty stage would otherwise render two
// rows (arrival + departure) before the traveler has picked anything. Entries
// already planned for the departure day always keep their row, so nothing a
// traveler added can silently disappear.
function hasDepartureRow(variantIndex: number, dayIndex: number): boolean {
    const day = editableVariants.value[variantIndex].days[dayIndex];

    return (
        stayIdentity(day) !== null ||
        (day.departure_activities?.length ?? 0) > 0 ||
        (day.departure_restaurants?.length ?? 0) > 0
    );
}

// Short "4 Jan" for the departure-day row — the checkout date is the last
// night's `date_to`, shortened the same way dayRailDate() shortens `date`.
function departureRailDate(day: { date_to?: string | null }): string {
    const [dayNumber, month] = (day.date_to ?? '').split(' ');

    return dayNumber ? [dayNumber, month].filter(Boolean).join(' ') : '';
}

// Short "15 Aug" for the day card's header — dates are stored as
// "D Mon YYYY", and the year is already spelled out on the stage heading's
// date range right above, so repeating it on every day says nothing new.
function dayRailDate(day: {
    date?: string | null;
    date_to?: string | null;
}): string {
    const [dayNumber, month] = dayDateLabel(day).split(' ');

    return dayNumber ? [dayNumber, month].filter(Boolean).join(' ') : '';
}

// What heads a day's card: its date, or — while the plan has no dates yet —
// at least its day number, so the header line is never blank.
function dayCardDateLabel(day: {
    day: number;
    date?: string | null;
    date_to?: string | null;
}): string {
    return dayRailDate(day) || t('itinerary.dayNumber', { n: day.day });
}

// `departure` keys the stage-end's extra departure-day row, which folds
// independently of the last night it hangs off.
function dayCollapseKey(
    variantIndex: number,
    dayIndex: number,
    departure = false,
): string {
    return `${variantIndex}-${dayIndex}${departure ? '-departure' : ''}`;
}

function isDayCollapsed(
    variantIndex: number,
    dayIndex: number,
    departure = false,
): boolean {
    return (
        collapsedDays.value[
            dayCollapseKey(variantIndex, dayIndex, departure)
        ] === true
    );
}

function toggleDayCollapsed(
    variantIndex: number,
    dayIndex: number,
    departure = false,
) {
    const key = dayCollapseKey(variantIndex, dayIndex, departure);

    collapsedDays.value = {
        ...collapsedDays.value,
        [key]: !collapsedDays.value[key],
    };
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
    // The subtitle beside the heading has to follow the new city, or the
    // stage reads "Swakopmund · Khomas" until the plan is regenerated.
    const region =
        regionCoords.value[newLocation.toLowerCase().trim()]?.region ?? null;

    for (let i = dayIndex; i <= endIndex; i++) {
        days[i].location = newLocation;
        days[i].region = region;
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

    // A read-only viewer has no business writing, and savePlan() would happily
    // mint them a private copy of someone else's plan if we let it through.
    if (readonly.value) {
        return;
    }

    // Diverged from the stored plan — see planConflict.
    if (planConflict.value) {
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
            currentVersion.value = await updatePlan(
                currentToken.value,
                combined,
                currentVersion.value,
            );
        } else {
            const result = await savePlan(combined);
            currentToken.value = result.token;
            currentVersion.value = result.version;
            currentShareToken.value = result.shareToken;
            currentOwned.value = result.owned;
            emit('update:token', result.token);
        }
    } catch (e) {
        if (e instanceof PlanConflictError) {
            planConflict.value = true;
        } else {
            console.warn('Failed to auto-save plan:', e);
        }
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
        editableVariants.value = normalizeVariants(
            JSON.parse(JSON.stringify(plan.variants)),
        );
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
        plan.variants.forEach((_, i) => {
            consolidateDepartureEntries(i);
            applyDates(i);
        });

        // Only skip the immediate re-save when hydrating a plan that's
        // *already* got a token (restored via ?trip=token — saving it right
        // back would be a redundant round-trip). A brand-new Kaia result has
        // no token yet, so it must NOT be skipped — that's the save that
        // mints the token and updates the URL in the first place.
        skipNextPersist = !!currentToken.value;

        // A replacement plan (regenerated from edited trip params) supersedes
        // whatever the conflict was about, so autosaving may resume.
        planConflict.value = false;
        currentVersion.value = props.version ?? null;
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
    () => props.version,
    (version) => {
        currentVersion.value = version ?? null;
    },
);

watch(
    () => props.shareToken,
    (token) => {
        currentShareToken.value = token ?? null;
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

// Departure-day entries live on the last night of their stage (see
// ItineraryDay.departure_activities). Any edit that changes which day that is
// — a night added, days dragged around, neighbouring stages merged by a swap
// — would leave them stranded mid-stage, where no departure row renders them.
// Move them onto whichever day ends the stage now instead of losing them.
function consolidateDepartureEntries(variantIndex: number) {
    const days = editableVariants.value[variantIndex].days;

    days.forEach((day, index) => {
        const hasEntries =
            (day.departure_activities?.length ?? 0) > 0 ||
            (day.departure_restaurants?.length ?? 0) > 0;

        if (!hasEntries || isStageEnd(variantIndex, index)) {
            return;
        }

        const end = days[stageEndIndex(variantIndex, index)];
        end.departure_activities = [
            ...(end.departure_activities ?? []),
            ...(day.departure_activities ?? []),
        ];
        end.departure_restaurants = [
            ...(end.departure_restaurants ?? []),
            ...(day.departure_restaurants ?? []),
        ];
        day.departure_activities = [];
        day.departure_restaurants = [];
    });
}

function renumberDays(variantIndex: number) {
    editableVariants.value[variantIndex].days.forEach((day, index) => {
        day.day = index + 1;
    });
    consolidateDepartureEntries(variantIndex);
    applyDates(variantIndex);
    collapsedDays.value = {};
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

// Every control that acts on a stay sits on the stage card, which stands for
// the whole run of nights — so they all write across the run, not just the day
// the card happens to start on. Setting one day would silently split a
// three-night stage into "one night at the new lodge, two at the old one".
function stageDayIndices(variantIndex: number, dayIndex: number): number[] {
    const endIndex = stageEndIndex(variantIndex, dayIndex);
    const indices: number[] = [];

    for (let i = dayIndex; i <= endIndex; i++) {
        indices.push(i);
    }

    return indices;
}

// Days are serialized into the saved plan independently, so each one owns its
// own copy of a listing rather than sharing a reference with its neighbours.
function cloneRef<T>(value: T): T {
    return JSON.parse(JSON.stringify(value ?? null)) as T;
}

function removeItem(variantIndex: number, dayIndex: number) {
    const days = editableVariants.value[variantIndex].days;

    for (const i of stageDayIndices(variantIndex, dayIndex)) {
        days[i].accommodation = null;
        days[i].room_selection = null;
    }

    swap.value = null;
    roomPickerKey.value = null;
}

// Extends a stay by one night: copies the stage's last day — same place, same
// accommodation, same room — and inserts it right after, so the run grows into
// one longer stage instead of splitting into two. The new night's own
// activities and restaurants start empty; a night added is a day not yet
// planned.
function addNight(variantIndex: number, dayIndex: number) {
    const days = editableVariants.value[variantIndex].days;
    const endIndex = stageEndIndex(variantIndex, dayIndex);
    const last = days[endIndex];

    days.splice(endIndex + 1, 0, {
        day: 0,
        location: last.location,
        // Same place means the same region — carry it over with the location
        // rather than making dayRegion() re-derive it from the coords index.
        region: last.region,
        accommodation: cloneRef(last.accommodation),
        room_selection: cloneRef(last.room_selection),
        activities: [],
        restaurants: [],
        departure_activities: [],
        departure_restaurants: [],
    });

    renumberDays(variantIndex);
    swap.value = null;
    roomPickerKey.value = null;
}

// A day owns two pairs of entry lists: the day itself, and the departure
// morning after its night (only ever populated on a stage's last day). Every
// read or write of an entry goes through this mapping, so "which list" is a
// single boolean everywhere instead of four field names.
type EntryListField =
    | 'activities'
    | 'restaurants'
    | 'departure_activities'
    | 'departure_restaurants';

function entryListField(
    type: 'activity' | 'restaurant',
    departure: boolean,
): EntryListField {
    if (type === 'activity') {
        return departure ? 'departure_activities' : 'activities';
    }

    return departure ? 'departure_restaurants' : 'restaurants';
}

// Merges a day's activities and restaurants into one chronological list —
// entries with a `time` sort ascending; entries without one keep their
// original relative order and sink to the end, so a plan with no times set
// yet (the common case today) renders exactly as before. With `departure`,
// the same merge over the departure-day lists instead.
function dayEntries(
    variantIndex: number,
    dayIndex: number,
    departure = false,
): DayEntry[] {
    const day = editableVariants.value[variantIndex].days[dayIndex];

    const entries: DayEntry[] = [
        ...(day[entryListField('activity', departure)] ?? []).map(
            (item, itemIndex): DayEntry => ({
                type: 'activity',
                item,
                itemIndex,
            }),
        ),
        ...(day[entryListField('restaurant', departure)] ?? []).map(
            (item, itemIndex): DayEntry => ({
                type: 'restaurant',
                item,
                itemIndex,
            }),
        ),
    ];

    return entries
        .map((entry, order) => ({ entry, order }))
        .sort((a, b) => {
            if (a.entry.item.time && b.entry.item.time) {
                return (
                    a.entry.item.time.localeCompare(b.entry.item.time) ||
                    a.order - b.order
                );
            }

            if (a.entry.item.time) {
                return -1;
            }

            if (b.entry.item.time) {
                return 1;
            }

            return a.order - b.order;
        })
        .map(({ entry }) => entry);
}

function setEntryTime(
    variantIndex: number,
    dayIndex: number,
    entry: DayEntry,
    value: string | null,
    departure = false,
) {
    const field = entryListField(entry.type, departure);
    const list = editableVariants.value[variantIndex].days[dayIndex][field];

    if (list?.[entry.itemIndex]) {
        list[entry.itemIndex].time = value;
    }
}

function removeArrayItem(
    variantIndex: number,
    dayIndex: number,
    field: EntryListField,
    itemIndex: number,
) {
    editableVariants.value[variantIndex].days[dayIndex][field]?.splice(
        itemIndex,
        1,
    );
    swap.value = null;
    roomPickerKey.value = null;
}

function removeDay(variantIndex: number, dayIndex: number) {
    const days = editableVariants.value[variantIndex].days;
    days.splice(dayIndex, 1);
    renumberDays(variantIndex);
    swap.value = null;
    roomPickerKey.value = null;
}

// The stage heading spans every night at one place, so its own menu removes
// the whole run — removing just the first day of a three-night stay from a
// control that reads "Otjiwarongo, 1 – 4 Jan" would surprise anyone.
function removeStage(variantIndex: number, dayIndex: number) {
    const days = editableVariants.value[variantIndex].days;
    const endIndex = stageEndIndex(variantIndex, dayIndex);

    days.splice(dayIndex, endIndex - dayIndex + 1);
    renumberDays(variantIndex);
    swap.value = null;
    roomPickerKey.value = null;
}

// afterDayIndex = -1 inserts before the first day
function addDay(variantIndex: number, afterDayIndex: number) {
    const days = editableVariants.value[variantIndex].days;
    const prevDay = afterDayIndex >= 0 ? days[afterDayIndex] : undefined;
    const prevLocation = prevDay?.location ?? '';
    days.splice(afterDayIndex + 1, 0, {
        day: 0,
        location: prevLocation,
        // Inherits the region with the location it was seeded from; a day
        // inserted before day 1 has neither, and dayRegion() copes with that.
        region: prevDay?.region ?? null,
        accommodation: null,
        activities: [],
        restaurants: [],
        departure_activities: [],
        departure_restaurants: [],
    });
    renumberDays(variantIndex);
    swap.value = null;
    roomPickerKey.value = null;
}

// --- Room type picker (real availability, see RoomTypePicker.vue) ---

function roomSelectionKey(variantIndex: number, dayIndex: number): string {
    return `${variantIndex}-${dayIndex}`;
}

function toggleRoomPicker(variantIndex: number, dayIndex: number) {
    const key = roomSelectionKey(variantIndex, dayIndex);
    roomPickerKey.value = roomPickerKey.value === key ? null : key;
}

// You book one room for the stay, not a different one per night — so the
// picked room applies to every night of the stage, as the stay card's label
// already claims it does.
function selectRoom(
    variantIndex: number,
    dayIndex: number,
    option: RoomOption,
) {
    const days = editableVariants.value[variantIndex].days;

    for (const i of stageDayIndices(variantIndex, dayIndex)) {
        days[i].room_selection = cloneRef(option);
    }

    roomPickerKey.value = null;
}

function clearRoom(variantIndex: number, dayIndex: number) {
    const days = editableVariants.value[variantIndex].days;

    for (const i of stageDayIndices(variantIndex, dayIndex)) {
        days[i].room_selection = null;
    }
}

// --- Activity / restaurant / vehicle unit picker ---

function entryPickerKey(
    variantIndex: number,
    dayIndex: number,
    type: 'activity' | 'restaurant',
    itemIndex: number,
    departure = false,
): string {
    return `${variantIndex}-${dayIndex}-${type}-${itemIndex}${departure ? '-d' : ''}`;
}

function vehiclePickerUnitKey(variantIndex: number): string {
    return `${variantIndex}-vehicle`;
}

function toggleEntryPicker(
    variantIndex: number,
    dayIndex: number,
    type: 'activity' | 'restaurant',
    itemIndex: number,
    departure = false,
) {
    const key = entryPickerKey(
        variantIndex,
        dayIndex,
        type,
        itemIndex,
        departure,
    );

    unitPickerKey.value = unitPickerKey.value === key ? null : key;
}

function toggleVehiclePicker(variantIndex: number) {
    const key = vehiclePickerUnitKey(variantIndex);

    unitPickerKey.value = unitPickerKey.value === key ? null : key;
}

function selectEntryUnit(
    variantIndex: number,
    dayIndex: number,
    type: 'activity' | 'restaurant',
    itemIndex: number,
    option: RoomOption,
    departure = false,
) {
    const day = editableVariants.value[variantIndex].days[dayIndex];
    let arr: ItineraryListingRef[] | undefined;

    if (departure) {
        arr =
            type === 'activity'
                ? day.departure_activities
                : day.departure_restaurants;
    } else {
        arr = type === 'activity' ? day.activities : day.restaurants;
    }

    if (arr?.[itemIndex]) {
        arr[itemIndex] = { ...arr[itemIndex], unit_selection: option };
    }

    unitPickerKey.value = null;
}

function selectVehicleUnit(variantIndex: number, option: RoomOption) {
    const vehicle = editableVariants.value[variantIndex].vehicle;

    if (vehicle) {
        editableVariants.value[variantIndex].vehicle = {
            ...vehicle,
            unit_selection: option,
        };
    }

    unitPickerKey.value = null;
}

function entryPickerDate(
    variantIndex: number,
    dayIndex: number,
    departure: boolean,
): string | null {
    const day = editableVariants.value[variantIndex].days[dayIndex];

    return (departure ? day.date_to : day.date) ?? null;
}

function variantDateRange(
    variantIndex: number,
): { checkIn: string; checkOut: string } | null {
    const days = editableVariants.value[variantIndex].days;

    if (!days.length) {
        return null;
    }

    const checkIn = days[0].date;

    if (!checkIn) {
        return null;
    }

    const lastDay = days[days.length - 1];

    if (lastDay.date_to) {
        return { checkIn, checkOut: lastDay.date_to };
    }

    const lastDate = parseDayDate(lastDay.date);

    if (!lastDate) {
        return null;
    }

    lastDate.setDate(lastDate.getDate() + 1);

    return { checkIn, checkOut: toIsoDate(lastDate) };
}

// --- Swap / Add panel ---

type SwapField = 'accommodation' | 'activity' | 'restaurant' | 'vehicle';

interface SwapState {
    key: string;
    variantIndex: number;
    dayIndex: number | null;
    field: SwapField;
    // Which entry in day.activities/day.restaurants this swap targets — null
    // both for accommodation/vehicle (never arrays) and for an "add another"
    // panel (appends instead of replacing an entry at an index).
    itemIndex: number | null;
    // Targets the day's departure-morning lists (departure_activities/
    // departure_restaurants) instead of the day's own — set by the stage-end
    // departure row's card.
    departure: boolean;
    // The listing currently occupying this slot, if any — kept out of its
    // own alternatives list.
    excludeId: number | null;
}

function swapKey(
    variantIndex: number,
    dayIndex: number | null,
    field: SwapField,
    itemIndex?: number | null,
    departure = false,
): string {
    return `${variantIndex}-${dayIndex ?? 'v'}-${field}-${itemIndex ?? 'new'}${departure ? '-departure' : ''}`;
}

// itemRef is undefined when opening as "add" (empty slot, or appending
// another activity/restaurant alongside existing ones), defined when
// swapping an existing item. itemIndex only applies to the activities/
// restaurants arrays — omit it to append rather than replace.
function openSwap(
    variantIndex: number,
    dayIndex: number | null,
    field: SwapField,
    itemRef?: ItineraryListingRef,
    itemIndex?: number | null,
    departure = false,
) {
    const key = swapKey(variantIndex, dayIndex, field, itemIndex, departure);

    if (swap.value?.key === key) {
        swap.value = null;
        roomPickerKey.value = null;

        return;
    }

    // ListingSwapModal fetches its own results (search + filter against
    // /listings/search) once it opens — nothing to pre-fetch here.
    swap.value = {
        key,
        variantIndex,
        dayIndex,
        field,
        itemIndex: itemIndex ?? null,
        departure,
        excludeId: itemRef?.id ?? null,
    };
}

// The day this swap targets — used to default ListingSwapModal's city filter
// to "the same city" the day is already in. The vehicle field has no day of
// its own (dayIndex is always null for it), so it falls back to the trip's
// own start location — most rentals are picked up where the trip begins.
const swapDayLocation = computed<string | null>(() => {
    if (!swap.value) {
        return null;
    }

    if (swap.value.field === 'vehicle') {
        return routeStart.value;
    }

    if (swap.value.dayIndex === null) {
        return null;
    }

    return (
        editableVariants.value[swap.value.variantIndex]?.days[
            swap.value.dayIndex
        ]?.location ?? null
    );
});

const swapModalTitle = computed(() => {
    if (!swap.value) {
        return '';
    }

    const fieldLabel = {
        accommodation: t('itinerary.stayLabel'),
        activity: t('itinerary.activityLabel'),
        restaurant: t('itinerary.dinnerLabel'),
        vehicle: t('itinerary.vehicleLabel'),
    }[swap.value.field];

    const verb =
        swap.value.excludeId === null
            ? t('itinerary.add')
            : t('itinerary.change');

    return `${verb}: ${fieldLabel}`;
});

function applySwap(alternative: ItineraryListingRef) {
    if (!swap.value) {
        return;
    }

    const { variantIndex, dayIndex, field, itemIndex, departure } = swap.value;
    const variant = editableVariants.value[variantIndex];

    if (field === 'vehicle') {
        variant.vehicle = alternative;
    } else if (field === 'accommodation' && dayIndex !== null) {
        // The swap modal deliberately lets the traveler pick a lodge in
        // another town ("same city" is preselected, not locked), so the
        // stage's own location has to follow the stay there — it's what the
        // heading, the map pin and the driving legs all read, and leaving it
        // behind is how a stage ends up claiming a city nobody sleeps in.
        const newCity = alternative.city?.trim() || null;
        const newRegion = newCity
            ? (alternative.region ??
              regionCoords.value[newCity.toLowerCase()]?.region ??
              null)
            : null;

        // The whole stage moves to the new lodge — see stageDayIndices().
        for (const i of stageDayIndices(variantIndex, dayIndex)) {
            variant.days[i].accommodation = cloneRef(alternative);
            variant.days[i].room_selection = null;

            if (newCity) {
                variant.days[i].location = newCity;
                variant.days[i].region = newRegion;
            }
        }

        // The new lodge may match a neighbouring stage's, merging the two
        // runs — whichever day ended the stage before may not end it now.
        consolidateDepartureEntries(variantIndex);
    } else if (dayIndex !== null && field !== 'accommodation') {
        const listField = entryListField(field, departure);
        const list = (variant.days[dayIndex][listField] ??= []);

        if (itemIndex !== null) {
            list[itemIndex] = alternative;
        } else {
            list.push(alternative);
        }
    }

    swap.value = null;
    roomPickerKey.value = null;
}

// --- Save & share (auth-gated) ---

// Called by SaveShareBar when the user isn't logged in
function onNeedAuth() {
    authIntent.value = 'save';
    showAuthModal.value = true;
}

/**
 * Booking needs an account (TripController::store is behind `auth`), so ask for
 * one here rather than letting the traveler fill in the whole guest form and
 * get bounced on submit. Nothing is lost either way — the plan itself keeps
 * autosaving on its token throughout.
 */
function onBookClick(variant: ItineraryVariant) {
    if (!isLoggedIn.value) {
        authIntent.value = 'book';
        pendingBookVariant.value = variant;
        showAuthModal.value = true;

        return;
    }

    emit('book', variant);
}

// After a successful login in the modal, pick up whatever was interrupted.
async function onAuthSuccess() {
    showAuthModal.value = false;

    if (authIntent.value === 'book') {
        const variant = pendingBookVariant.value;
        pendingBookVariant.value = null;

        if (variant) {
            // No explicit save first: booking claims the plan for this account
            // server-side, which is the same thing saving would have done.
            emit('book', variant);
        }

        return;
    }

    await saveAllVariants();
}

async function saveAllVariants() {
    const results = await Promise.allSettled(
        editableVariants.value.map((variant, i) => {
            // The common case (a single variant) is already sitting behind
            // currentToken from auto-persist — claim that row for the account
            // instead of minting a second, orphaned token whose /trip/ link
            // would silently diverge from the ?trip= link already in the
            // address bar (same plan content, two different saved rows).
            //
            // Claiming is the whole point of this call: the plan was persisted
            // anonymously while the traveler was logged out, so without it the
            // row keeps user_id = null and never reaches their dashboard —
            // which is exactly what "save" was silently failing to do before.
            if (editableVariants.value.length === 1 && currentToken.value) {
                savedTokens.value[i] = currentToken.value;

                return claimPlan(currentToken.value).then(() => {
                    currentOwned.value = true;
                });
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

function estimatedTotal(variant: ItineraryVariant): number | null {
    let amount = 0;
    let hasAnyPrice = false;

    for (const day of variant.days) {
        const items = [
            day.accommodation,
            ...(day.activities ?? []),
            ...(day.restaurants ?? []),
            ...(day.departure_activities ?? []),
            ...(day.departure_restaurants ?? []),
        ];

        for (const item of items) {
            const cost = itemCost(item);

            if (cost !== null) {
                amount += cost;
                hasAnyPrice = true;
            }
        }
    }

    const vehicle = vehicleTotal(variant);

    if (vehicle !== null) {
        amount += vehicle;
        hasAnyPrice = true;
    }

    return hasAnyPrice ? amount : null;
}

// The vehicle is the one line the plan multiplies by the trip length itself
// (it appears once, not once per day), so it's the one place the difference
// between a daily rate and a flat package price actually changes the sum. An
// unrecorded unit keeps the old behaviour — a daily rate, which is what the
// column's absence has always implicitly meant here.
function vehicleTotal(variant: ItineraryVariant): number | null {
    const perCharge = itemCost(variant.vehicle);

    if (perCharge === null) {
        return null;
    }

    return isRecurring(variant.vehicle?.price_unit ?? 'per_day')
        ? perCharge * variant.days.length
        : perCharge;
}

function estimatedLabel(variant: ItineraryVariant): string | null {
    const amount = estimatedTotal(variant);

    return amount === null
        ? null
        : t('itinerary.estimated', { price: formatPrice(amount) });
}

// Total ÷ trip length — a pure display derivation of estimatedTotal() above,
// not a separately tracked figure, so it can't drift out of sync with it.
function estimatedPerDayLabel(variant: ItineraryVariant): string | null {
    const amount = estimatedTotal(variant);

    if (amount === null || variant.days.length === 0) {
        return null;
    }

    return t('itinerary.estimatedPerDay', {
        price: formatPrice(amount / variant.days.length),
    });
}

function vehicleEstimatedLabel(variant: ItineraryVariant): string | null {
    const amount = vehicleTotal(variant);

    return amount === null
        ? null
        : t('itinerary.estimated', { price: formatPrice(amount) });
}

// Derived from the same total as the line above rather than printing
// `price_from` straight through, which was only ever right while every vehicle
// was assumed to be quoted per day: a per-booking package divided by the trip
// length is what that price costs per day, and a per-person daily rate has to
// carry the party's multiplier here too.
function vehicleEstimatedPerDayLabel(variant: ItineraryVariant): string | null {
    const amount = vehicleTotal(variant);

    if (amount === null || variant.days.length === 0) {
        return null;
    }

    return t('itinerary.estimatedPerDay', {
        price: formatPrice(amount / variant.days.length),
    });
}
</script>

<template>
    <section id="itinerary-section">
        <!-- The stored plan moved on under us (same link open elsewhere, or
             another tab). Autosaving has stopped rather than overwrite the
             other side — say so plainly instead of letting edits pile up
             looking saved. -->
        <div v-if="planConflict" class="plan-conflict-banner" role="alert">
            <span>{{ t('itinerary.conflict.message') }}</span>
            <button
                type="button"
                class="plan-conflict-reload"
                @click="reloadPlan()"
            >
                {{ t('itinerary.conflict.reload') }}
            </button>
        </div>

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
                    :class="{
                        'variant-head--single': editableVariants.length === 1,
                    }"
                >
                    <h3>{{ variant.name }}</h3>
                    <div class="variant-head-actions">
                        <!--
                            Hidden for a read-only visitor: the only token they
                            hold is the share one, which claimPlan rejects.
                            "Save someone else's plan to my account" would have
                            to mean copying it, and that isn't built.
                        -->
                        <SaveButton
                            v-if="!readonly && editableVariants.length === 1"
                            :plan="{
                                trip_summary: currentTripSummary,
                                variants: [variant],
                                start_location: routeStart,
                                end_location: routeEnd,
                                trip_params: currentTripParams,
                            }"
                            :existing-token="currentToken"
                            :share-token="currentShareToken"
                            :owned="currentOwned"
                            :is-logged-in="isLoggedIn"
                            @saved="
                                (token) => {
                                    savedTokens[variantIndex] = token;
                                    currentOwned = true;
                                }
                            "
                            @need-auth="onNeedAuth"
                        />
                        <ShareButton
                            v-if="editableVariants.length === 1"
                            :plan="{
                                trip_summary: currentTripSummary,
                                variants: [variant],
                                start_location: routeStart,
                                end_location: routeEnd,
                                trip_params: currentTripParams,
                            }"
                            :existing-token="currentToken"
                            :share-token="currentShareToken"
                            @saved="
                                (token) => {
                                    savedTokens[variantIndex] = token;
                                }
                            "
                        />
                        <KebabMenu
                            v-if="!readonly && editableVariants.length === 1"
                            :items="[
                                { key: 'edit', label: t('itinerary.editPlan') },
                            ]"
                            :label="t('itinerary.editPlan')"
                            @select="openParamsEditor"
                        />
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
                    <span
                        v-if="estimatedPerDayLabel(variant)"
                        class="variant-price-per-day"
                        >({{ estimatedPerDayLabel(variant) }})</span
                    >
                </div>

                <TripMeta
                    :trip-params="currentTripParams"
                    :route-start="routeStart"
                    :route-end="routeEnd"
                    :editable="!readonly && editableVariants.length > 1"
                    @edit="openParamsEditor"
                />
                <div v-if="regenerating" class="params-regenerating-note">
                    {{ t('itinerary.paramsEditor.saving') }}
                </div>

                <template v-if="variant.vehicle">
                    <div class="vehicle-card">
                        <img
                            v-if="variant.vehicle.image"
                            v-bind="thumbAttrs(variant.vehicle.image, 64)"
                            :alt="variant.vehicle.name"
                            class="vehicle-card-img"
                            width="64"
                            height="48"
                            loading="lazy"
                            decoding="async"
                            @error="onImageError($event, 'vehicle')"
                        />
                        <div class="vehicle-card-body">
                            <ItineraryLineItem
                                keypath="itinerary.vehicle"
                                :item-ref="variant.vehicle"
                                :readonly="readonly"
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
                                @choose-unit="toggleVehiclePicker(variantIndex)"
                            />
                            <AvailabilityPicker
                                v-if="
                                    !readonly &&
                                    variant.vehicle?.slug &&
                                    unitPickerKey ===
                                        vehiclePickerUnitKey(variantIndex)
                                "
                                :slug="variant.vehicle.slug"
                                :check-in="
                                    variantDateRange(variantIndex)?.checkIn ??
                                    null
                                "
                                :check-out="
                                    variantDateRange(variantIndex)?.checkOut ??
                                    null
                                "
                                :adults="currentTripParams?.adults ?? 2"
                                :children="
                                    currentTripParams?.children_under_13 ?? 0
                                "
                                :title="t('itinerary.chooseVehicleUnit')"
                                @select="
                                    (option) =>
                                        selectVehicleUnit(variantIndex, option)
                                "
                            />
                            <div
                                v-if="vehicleEstimatedLabel(variant)"
                                class="vehicle-price"
                            >
                                {{ vehicleEstimatedLabel(variant) }}
                                <span
                                    v-if="vehicleEstimatedPerDayLabel(variant)"
                                    class="vehicle-price-per-day"
                                    >({{
                                        vehicleEstimatedPerDayLabel(variant)
                                    }})</span
                                >
                            </div>
                        </div>
                    </div>
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
                        <div class="days-col-top-row">
                            <button
                                v-if="!readonly"
                                type="button"
                                class="add-day-btn"
                                @click="addDay(variantIndex, -1)"
                            >
                                + {{ t('itinerary.addStage') }}
                            </button>
                            <button
                                v-if="!readonly"
                                type="button"
                                class="reverse-route-btn"
                                @click="reverseVariant(variantIndex)"
                            >
                                ⇄ {{ t('itinerary.reverseRoute') }}
                            </button>
                        </div>

                        <div class="itinerary-timeline">
                            <draggable
                                v-model="editableVariants[variantIndex].days"
                                item-key="day"
                                handle=".drag-handle"
                                :animation="150"
                                ghost-class="day-item--ghost"
                                @end="renumberDays(variantIndex)"
                            >
                                <template
                                    #item="{ element: day, index: dayIndex }"
                                >
                                    <div class="day-item">
                                        <!-- Only between stages: inside one,
                                             there is nothing to drive and the
                                             days should read as one block. -->
                                        <div
                                            v-if="
                                                dayIndex > 0 &&
                                                isStageStart(
                                                    variantIndex,
                                                    dayIndex,
                                                )
                                            "
                                            class="day-connector"
                                        >
                                            <div
                                                class="day-connector-rail"
                                            ></div>
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
                                                        viewBox="-5 0 35 30"
                                                        aria-hidden="true"
                                                        focusable="false"
                                                    >
                                                        <path
                                                            d="M 0.2,8.0 A 3.5,4.0 0 0,0 -3.5,12.0 A 3.5,4.0 0 0,0 0.2,16.0 Z M 0.2,9.5 A 2.0,2.5 0 0,0 -2.0,12.0 A 2.0,2.5 0 0,0 0.2,14.5 Z"
                                                        />
                                                        <path
                                                            d="M10.984375 4.9863281 A 1.0001 1.0001 0 0 0 10.839844 5L3 5C1.3550302 5 0 6.3550302 0 8L0 16L0 19C0 20.105 0.895 21 2 21L3.0507812 21C3.2981765 22.685411 4.7500665 24 6.5 24C8.2499335 24 9.7018235 22.685411 9.9492188 21L21.050781 21C21.298177 22.685411 22.750067 24 24.5 24C26.366331 24 27.897818 22.506531 27.984375 20.660156L28.623047 20.451172C29.445047 20.182172 30 19.414781 30 18.550781L30 14.572266C30 13.649266 29.367703 12.846906 28.470703 12.628906L23.021484 11.308594L19.412109 6.2578125C18.850001 5.4693624 17.938685 5 16.970703 5L11.154297 5 A 1.0001 1.0001 0 0 0 10.984375 4.9863281 z M 12 7L16.970703 7C17.294722 7 17.595311 7.1544188 17.783203 7.4179688L20.341797 11L12 11L12 7 z M 6.5 19C7.3402718 19 8 19.659728 8 20.5C8 21.340272 7.3402718 22 6.5 22C5.6597282 22 5 21.340272 5 20.5C5 19.659728 5.6597282 19 6.5 19 z M 24.5 19C25.340272 19 26 19.659728 26 20.5C26 21.340272 25.340272 22 24.5 22C23.659728 22 23 21.340272 23 20.5C23 19.659728 23.659728 19 24.5 19 z"
                                                        />
                                                    </svg>
                                                    <span
                                                        class="drive-time-label"
                                                    >
                                                        {{
                                                            drivingTimeBetween(
                                                                variantIndex,
                                                                editableVariants[
                                                                    variantIndex
                                                                ].days[
                                                                    dayIndex - 1
                                                                ].location,
                                                                day.location,
                                                            )
                                                        }}
                                                    </span>
                                                    <span
                                                        class="drive-time-schedule"
                                                    >
                                                        <label
                                                            class="drive-time-departure"
                                                        >
                                                            {{
                                                                t(
                                                                    'itinerary.departure',
                                                                )
                                                            }}
                                                            <input
                                                                type="time"
                                                                class="drive-time-input"
                                                                :disabled="
                                                                    readonly
                                                                "
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
                                                                    ].days[
                                                                        dayIndex -
                                                                            1
                                                                    ].location,
                                                                    day.location,
                                                                )
                                                            "
                                                            class="drive-time-arrival"
                                                        >
                                                            ·
                                                            {{
                                                                t(
                                                                    'itinerary.arrival',
                                                                )
                                                            }}
                                                            ≈
                                                            {{
                                                                arrivalTime(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                    editableVariants[
                                                                        variantIndex
                                                                    ].days[
                                                                        dayIndex -
                                                                            1
                                                                    ].location,
                                                                    day.location,
                                                                )
                                                            }}
                                                        </span>
                                                    </span>
                                                </div>
                                                <button
                                                    v-if="!readonly"
                                                    type="button"
                                                    class="add-day-inline-btn"
                                                    :title="
                                                        t('itinerary.addStage')
                                                    "
                                                    :aria-label="
                                                        t('itinerary.addStage')
                                                    "
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

                                        <!-- The stage heading row: where the
                                             traveler sleeps, shown once for the
                                             whole run of days at that place. -->
                                        <div
                                            v-if="
                                                isStageStart(
                                                    variantIndex,
                                                    dayIndex,
                                                )
                                            "
                                            class="day-content"
                                        >
                                            <div class="day-rail">
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

                                            <div class="day-card-column">
                                                <div class="day-card">
                                                    <span
                                                        v-if="!readonly"
                                                        class="drag-handle"
                                                        :title="
                                                            t(
                                                                'itinerary.dragToReorder',
                                                            )
                                                        "
                                                    >
                                                        <GripHorizontal
                                                            :size="14"
                                                        />
                                                    </span>
                                                    <div
                                                        class="day-card-header"
                                                    >
                                                        <button
                                                            v-if="
                                                                dayThumbnail(
                                                                    day,
                                                                )
                                                            "
                                                            type="button"
                                                            class="day-thumb-btn"
                                                            :aria-label="
                                                                t(
                                                                    'itinerary.viewPhotos',
                                                                )
                                                            "
                                                            @click="
                                                                openStageLightbox(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                    day,
                                                                )
                                                            "
                                                        >
                                                            <img
                                                                v-bind="
                                                                    thumbAttrs(
                                                                        dayThumbnail(
                                                                            day,
                                                                        )!,
                                                                        44,
                                                                    )
                                                                "
                                                                alt=""
                                                                class="day-thumb"
                                                                width="44"
                                                                height="44"
                                                                loading="lazy"
                                                                decoding="async"
                                                                @error="
                                                                    onImageError(
                                                                        $event,
                                                                    )
                                                                "
                                                            />
                                                        </button>
                                                        <div
                                                            class="day-card-title"
                                                        >
                                                            <div
                                                                class="day-card-title-row"
                                                            >
                                                                <LocationPicker
                                                                    :readonly="
                                                                        readonly
                                                                    "
                                                                    :model-value="
                                                                        day.location
                                                                    "
                                                                    :label="
                                                                        dayCity(
                                                                            day,
                                                                        )
                                                                    "
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
                                                                    v-if="
                                                                        dayRegion(
                                                                            day,
                                                                        )
                                                                    "
                                                                    class="day-card-region"
                                                                    >{{
                                                                        dayRegion(
                                                                            day,
                                                                        )
                                                                    }}</span
                                                                >
                                                            </div>
                                                            <div
                                                                v-if="day.date"
                                                                class="day-card-sub"
                                                            >
                                                                {{
                                                                    stageDateSubLabel(
                                                                        variantIndex,
                                                                        dayIndex,
                                                                    )
                                                                }}
                                                            </div>
                                                        </div>
                                                        <div
                                                            class="day-card-header-right"
                                                        >
                                                            <span
                                                                v-if="
                                                                    stagePriceLabel(
                                                                        variantIndex,
                                                                        dayIndex,
                                                                    )
                                                                "
                                                                class="day-card-price"
                                                                >{{
                                                                    stagePriceLabel(
                                                                        variantIndex,
                                                                        dayIndex,
                                                                    )
                                                                }}<span
                                                                    v-if="
                                                                        stagePriceSubLabel(
                                                                            variantIndex,
                                                                            dayIndex,
                                                                        )
                                                                    "
                                                                    class="day-card-price-sub"
                                                                    >{{
                                                                        stagePriceSubLabel(
                                                                            variantIndex,
                                                                            dayIndex,
                                                                        )
                                                                    }}</span
                                                                ></span
                                                            >
                                                            <div
                                                                class="day-card-header-actions"
                                                            >
                                                                <KebabMenu
                                                                    v-if="
                                                                        !readonly
                                                                    "
                                                                    :items="[
                                                                        {
                                                                            key: 'delete',
                                                                            label: t(
                                                                                'itinerary.removeStage',
                                                                            ),
                                                                            danger: true,
                                                                        },
                                                                    ]"
                                                                    :label="
                                                                        t(
                                                                            'itinerary.stageOptions',
                                                                        )
                                                                    "
                                                                    @select="
                                                                        confirmAndRun(
                                                                            t(
                                                                                'itinerary.confirmRemove.stage',
                                                                            ),
                                                                            () =>
                                                                                removeStage(
                                                                                    variantIndex,
                                                                                    dayIndex,
                                                                                ),
                                                                        )
                                                                    "
                                                                />
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="day-card-grid">
                                                        <ItineraryStayCard
                                                            :readonly="readonly"
                                                            :stay="
                                                                day.accommodation
                                                            "
                                                            :booking-status="
                                                                day.accommodation?.id
                                                                    ? (bookingStatusByListingId.get(
                                                                          day.accommodation.id,
                                                                      ) ?? null)
                                                                    : null
                                                            "
                                                            :date-range-label="
                                                                day.date
                                                                    ? stageDateRangeLabel(
                                                                          variantIndex,
                                                                          dayIndex,
                                                                      )
                                                                    : ''
                                                            "
                                                            :nights="
                                                                stageNights(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                )
                                                            "
                                                            :room-selection="
                                                                day.room_selection
                                                            "
                                                            @add="
                                                                openSwap(
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
                                                            @remove="
                                                                confirmAndRun(
                                                                    t(
                                                                        'itinerary.confirmRemove.item',
                                                                    ),
                                                                    () =>
                                                                        removeItem(
                                                                            variantIndex,
                                                                            dayIndex,
                                                                        ),
                                                                )
                                                            "
                                                            @choose-room="
                                                                toggleRoomPicker(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                )
                                                            "
                                                            @clear-room="
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
                                                            @add-night="
                                                                addNight(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                )
                                                            "
                                                        />
                                                    </div>

                                                    <AvailabilityPicker
                                                        v-if="
                                                            !readonly &&
                                                            roomPickerKey ===
                                                                roomSelectionKey(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                )
                                                        "
                                                        :slug="
                                                            day.accommodation
                                                                ?.slug ?? null
                                                        "
                                                        :check-in="
                                                            stageDateRangeIso(
                                                                variantIndex,
                                                                dayIndex,
                                                            )?.checkIn ?? null
                                                        "
                                                        :check-out="
                                                            stageDateRangeIso(
                                                                variantIndex,
                                                                dayIndex,
                                                            )?.checkOut ?? null
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
                                                                ?.gallery
                                                                ?.length
                                                                ? day
                                                                      .accommodation
                                                                      .gallery
                                                                : day
                                                                        .accommodation
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
                                                </div>

                                                <div class="day-plans-head">
                                                    <div
                                                        class="day-plans-title"
                                                    >
                                                        {{
                                                            t(
                                                                'itinerary.dayPlansLabel',
                                                            )
                                                        }}
                                                    </div>
                                                    <p class="day-plans-hint">
                                                        {{
                                                            t(
                                                                'itinerary.dayPlansHint',
                                                            )
                                                        }}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Every day of the stage gets its own
                                             row: the rail keeps only the small
                                             day marker for orientation — the
                                             date itself heads the card, next
                                             to that day's activities and
                                             restaurants. -->
                                        <div
                                            class="day-content day-content--day"
                                        >
                                            <div class="day-rail day-rail--day">
                                                <span class="day-dot">{{
                                                    day.day
                                                }}</span>
                                            </div>

                                            <ItineraryDayPlanCard
                                                :readonly="readonly"
                                                :date-label="
                                                    dayCardDateLabel(day)
                                                "
                                                :day-label="
                                                    dayRailLabel(
                                                        variantIndex,
                                                        dayIndex,
                                                    )
                                                "
                                                :collapsed="
                                                    isDayCollapsed(
                                                        variantIndex,
                                                        dayIndex,
                                                    )
                                                "
                                                :entries="
                                                    dayEntries(
                                                        variantIndex,
                                                        dayIndex,
                                                    )
                                                "
                                                @toggle-collapse="
                                                    toggleDayCollapsed(
                                                        variantIndex,
                                                        dayIndex,
                                                    )
                                                "
                                                @add="
                                                    (type) =>
                                                        openSwap(
                                                            variantIndex,
                                                            dayIndex,
                                                            type,
                                                        )
                                                "
                                                @swap="
                                                    (entry) =>
                                                        openSwap(
                                                            variantIndex,
                                                            dayIndex,
                                                            entry.type,
                                                            entry.item,
                                                            entry.itemIndex,
                                                        )
                                                "
                                                @remove="
                                                    (entry) =>
                                                        confirmAndRun(
                                                            t(
                                                                'itinerary.confirmRemove.item',
                                                            ),
                                                            () =>
                                                                removeArrayItem(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                    entryListField(
                                                                        entry.type,
                                                                        false,
                                                                    ),
                                                                    entry.itemIndex,
                                                                ),
                                                        )
                                                "
                                                @update-time="
                                                    (entry, value) =>
                                                        setEntryTime(
                                                            variantIndex,
                                                            dayIndex,
                                                            entry,
                                                            value,
                                                        )
                                                "
                                                @remove-day="
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
                                                @choose-unit="
                                                    (entry) =>
                                                        toggleEntryPicker(
                                                            variantIndex,
                                                            dayIndex,
                                                            entry.type,
                                                            entry.itemIndex,
                                                        )
                                                "
                                            />
                                            <template v-if="!readonly">
                                                <template
                                                    v-for="entry in dayEntries(
                                                        variantIndex,
                                                        dayIndex,
                                                    )"
                                                    :key="`picker-${entry.type}-${entry.itemIndex}`"
                                                >
                                                    <AvailabilityPicker
                                                        v-if="
                                                            entry.item.slug &&
                                                            unitPickerKey ===
                                                                entryPickerKey(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                    entry.type,
                                                                    entry.itemIndex,
                                                                )
                                                        "
                                                        :slug="entry.item.slug"
                                                        :check-in="
                                                            entryPickerDate(
                                                                variantIndex,
                                                                dayIndex,
                                                                false,
                                                            )
                                                        "
                                                        :adults="
                                                            currentTripParams?.adults ??
                                                            2
                                                        "
                                                        :children="
                                                            currentTripParams?.children_under_13 ??
                                                            0
                                                        "
                                                        :title="
                                                            t(
                                                                'itinerary.chooseSlot',
                                                            )
                                                        "
                                                        @select="
                                                            (option) =>
                                                                selectEntryUnit(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                    entry.type,
                                                                    entry.itemIndex,
                                                                    option,
                                                                )
                                                        "
                                                    />
                                                </template>
                                            </template>
                                        </div>

                                        <!-- The departure day: checkout is the
                                             morning after the stage's last
                                             night (`date_to`), so it gets its
                                             own row — there is still time for
                                             an activity before driving on. -->
                                        <div
                                            v-if="
                                                isStageEnd(
                                                    variantIndex,
                                                    dayIndex,
                                                ) &&
                                                hasDepartureRow(
                                                    variantIndex,
                                                    dayIndex,
                                                )
                                            "
                                            class="day-content day-content--day"
                                        >
                                            <div class="day-rail day-rail--day">
                                                <span
                                                    class="day-dot day-dot--departure"
                                                ></span>
                                            </div>

                                            <ItineraryDayPlanCard
                                                :readonly="readonly"
                                                :date-label="
                                                    departureRailDate(day)
                                                "
                                                :day-label="
                                                    t('itinerary.dayDeparture')
                                                "
                                                :collapsed="
                                                    isDayCollapsed(
                                                        variantIndex,
                                                        dayIndex,
                                                        true,
                                                    )
                                                "
                                                :entries="
                                                    dayEntries(
                                                        variantIndex,
                                                        dayIndex,
                                                        true,
                                                    )
                                                "
                                                hide-day-menu
                                                @toggle-collapse="
                                                    toggleDayCollapsed(
                                                        variantIndex,
                                                        dayIndex,
                                                        true,
                                                    )
                                                "
                                                @add="
                                                    (type) =>
                                                        openSwap(
                                                            variantIndex,
                                                            dayIndex,
                                                            type,
                                                            undefined,
                                                            null,
                                                            true,
                                                        )
                                                "
                                                @swap="
                                                    (entry) =>
                                                        openSwap(
                                                            variantIndex,
                                                            dayIndex,
                                                            entry.type,
                                                            entry.item,
                                                            entry.itemIndex,
                                                            true,
                                                        )
                                                "
                                                @remove="
                                                    (entry) =>
                                                        confirmAndRun(
                                                            t(
                                                                'itinerary.confirmRemove.item',
                                                            ),
                                                            () =>
                                                                removeArrayItem(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                    entryListField(
                                                                        entry.type,
                                                                        true,
                                                                    ),
                                                                    entry.itemIndex,
                                                                ),
                                                        )
                                                "
                                                @update-time="
                                                    (entry, value) =>
                                                        setEntryTime(
                                                            variantIndex,
                                                            dayIndex,
                                                            entry,
                                                            value,
                                                            true,
                                                        )
                                                "
                                                @choose-unit="
                                                    (entry) =>
                                                        toggleEntryPicker(
                                                            variantIndex,
                                                            dayIndex,
                                                            entry.type,
                                                            entry.itemIndex,
                                                            true,
                                                        )
                                                "
                                            />
                                            <template v-if="!readonly">
                                                <template
                                                    v-for="entry in dayEntries(
                                                        variantIndex,
                                                        dayIndex,
                                                        true,
                                                    )"
                                                    :key="`picker-d-${entry.type}-${entry.itemIndex}`"
                                                >
                                                    <AvailabilityPicker
                                                        v-if="
                                                            entry.item.slug &&
                                                            unitPickerKey ===
                                                                entryPickerKey(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                    entry.type,
                                                                    entry.itemIndex,
                                                                    true,
                                                                )
                                                        "
                                                        :slug="entry.item.slug"
                                                        :check-in="
                                                            entryPickerDate(
                                                                variantIndex,
                                                                dayIndex,
                                                                true,
                                                            )
                                                        "
                                                        :adults="
                                                            currentTripParams?.adults ??
                                                            2
                                                        "
                                                        :children="
                                                            currentTripParams?.children_under_13 ??
                                                            0
                                                        "
                                                        :title="
                                                            t(
                                                                'itinerary.chooseSlot',
                                                            )
                                                        "
                                                        @select="
                                                            (option) =>
                                                                selectEntryUnit(
                                                                    variantIndex,
                                                                    dayIndex,
                                                                    entry.type,
                                                                    entry.itemIndex,
                                                                    option,
                                                                    true,
                                                                )
                                                        "
                                                    />
                                                </template>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </draggable>
                        </div>

                        <button
                            v-if="!readonly"
                            type="button"
                            class="add-day-btn"
                            @click="
                                addDay(variantIndex, variant.days.length - 1)
                            "
                        >
                            + {{ t('itinerary.addStage') }}
                        </button>
                    </div>
                </div>

                <button
                    v-if="!readonly"
                    class="cta"
                    @click="onBookClick(variant)"
                >
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
                    :share-token="currentShareToken"
                    :owned="currentOwned"
                    :is-logged-in="isLoggedIn"
                    @saved="
                        (token) => {
                            savedTokens[variantIndex] = token;
                        }
                    "
                />
            </div>
        </div>

        <SaveLoginModal
            v-if="showAuthModal"
            :intent="authIntent"
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

        <ListingSwapModal
            v-if="swap"
            :type="swap.field"
            :title="swapModalTitle"
            :exclude-id="swap.excludeId"
            :default-city="swapDayLocation"
            :cities="dbCities"
            @select="applySwap"
            @close="swap = null"
        />

        <button
            v-if="editableVariants.length === 1"
            type="button"
            class="mobile-map-fab"
            :aria-label="t('itinerary.showMap')"
            :title="t('itinerary.showMap')"
            @click="mapModalOpen = true"
        >
            🗺️
        </button>

        <MapViewModal
            v-if="mapModalOpen && editableVariants.length === 1"
            :variant="editableVariants[0]"
            :region-coords="regionCoords"
            map-id="trip-map-modal-0"
            @close="mapModalOpen = false"
            @driving-legs="
                (legs) => {
                    drivingLegsPerVariant[0] = legs;
                }
            "
        />

        <ImageLightbox
            v-if="lightbox"
            :images="lightbox.images"
            :index="lightbox.index"
            @update:index="(value) => lightbox && (lightbox.index = value)"
            @close="lightbox = null"
        />
    </section>
</template>
