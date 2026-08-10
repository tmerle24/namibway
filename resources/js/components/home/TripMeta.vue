<script setup lang="ts">
import {
    Calendar,
    Car,
    Compass,
    Moon,
    Pencil,
    Sparkles,
    Users,
    Wallet,
} from '@lucide/vue';
import type { ComponentPublicInstance, FunctionalComponent } from 'vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatPrice } from '@/lib/currency';
import type { TripParams } from '@/lib/kaia-types';

const props = defineProps<{
    tripParams?: TripParams | null;
    routeStart?: string | null;
    routeEnd?: string | null;
    editable?: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit'): void;
}>();

const { t } = useI18n();

const travelersLabel = computed(() => {
    const p = props.tripParams;

    if (!p || !p.adults) {
        return null;
    }

    const parts = [t('itinerary.meta.adults', p.adults)];

    if (p.children_under_13) {
        const childrenLabel = t('itinerary.meta.children', p.children_under_13);
        parts.push(
            p.children_ages
                ? `${childrenLabel} (${p.children_ages})`
                : childrenLabel,
        );
    }

    return parts.join(', ');
});

// The vehicle reads as one fact ("4x4 camper, up to € 65/Tag"), not two
// chips: the budget is a qualifier on the choice, and on a phone every extra
// chip costs a line in a row that already clamps.
const vehicleLabel = computed(() => {
    const p = props.tripParams;

    if (!p?.vehicle_class) {
        return null;
    }

    const name = t(`itinerary.vehiclePicker.classes.${p.vehicle_class}.name`);
    const budget = p.vehicle_daily_budget
        ? formatPrice(p.vehicle_daily_budget)
        : null;

    return budget
        ? `${name}, ${t('itinerary.meta.upToPerDay', { price: budget })}`
        : name;
});

const durationLabel = computed(() => {
    const nights = props.tripParams?.nights;

    return nights ? t('itinerary.meta.nights', nights) : null;
});

interface MetaItem {
    icon: FunctionalComponent;
    label: string;
    value: string;
}

const routeLabel = computed(() => {
    if (!props.routeStart || !props.routeEnd) {
        return null;
    }

    return props.routeStart.trim().toLowerCase() ===
        props.routeEnd.trim().toLowerCase()
        ? props.routeStart
        : `${props.routeStart} → ${props.routeEnd}`;
});

const items = computed<MetaItem[]>(() => {
    const p = props.tripParams;
    const list: (MetaItem | null)[] = [
        routeLabel.value
            ? {
                  icon: Compass,
                  label: t('itinerary.route'),
                  value: routeLabel.value,
              }
            : null,
    ];

    if (!p) {
        return list.filter((item): item is MetaItem => item !== null);
    }

    list.push(
        p.travel_period
            ? {
                  icon: Calendar,
                  label: t('itinerary.meta.travelPeriod'),
                  value: p.travel_period,
              }
            : null,
        durationLabel.value
            ? {
                  icon: Moon,
                  label: t('itinerary.meta.duration'),
                  value: durationLabel.value,
              }
            : null,
        travelersLabel.value
            ? {
                  icon: Users,
                  label: t('itinerary.meta.travelers'),
                  value: travelersLabel.value,
              }
            : null,
        p.interests
            ? {
                  icon: Sparkles,
                  label: t('itinerary.meta.preferences'),
                  value: p.interests,
              }
            : null,
        p.budget_tier
            ? {
                  icon: Wallet,
                  label: t('itinerary.meta.budget'),
                  value: t(`itinerary.meta.budgetTiers.${p.budget_tier}`),
              }
            : null,
        vehicleLabel.value
            ? {
                  icon: Car,
                  label: t('itinerary.meta.vehicle'),
                  value: vehicleLabel.value,
              }
            : null,
    );

    return list.filter((item): item is MetaItem => item !== null);
});

// Collapses the chip row to ~2 lines on narrow screens, revealed via a
// "show more" toggle — chips wrap to as many rows as content needs (route +
// dates + duration + travelers + preferences + budget easily reaches 4-5 on
// a phone), which otherwise pushes the itinerary itself far down the page.
// scrollHeight vs clientHeight (rather than counting chips) accounts for
// how much actual chip content varies in width.
//
// The clamped row itself is the wrong thing to ResizeObserve: overflow:
// hidden + a fixed max-height means its own box never changes size no
// matter how much content is stuffed inside, so it never re-fires. Instead,
// measure it directly on mount/content-change, and again whenever the
// (unclamped) wrapper's width changes — e.g. the viewport being resized —
// since that's what actually changes how the chips wrap.
const expanded = ref(false);
const hasOverflow = ref(false);
let rowEl: HTMLElement | null = null;
let wrapObserver: ResizeObserver | null = null;

function measureOverflow() {
    if (!rowEl) {
        return;
    }

    hasOverflow.value = rowEl.scrollHeight > rowEl.clientHeight + 1;
}

function setRowRef(el: Element | ComponentPublicInstance | null) {
    rowEl = el instanceof HTMLElement ? el : null;
    // The element isn't necessarily flushed into the live DOM yet at the
    // moment this ref callback fires — scrollHeight/clientHeight would both
    // read 0 here. nextTick() waits for that flush before measuring.
    nextTick(measureOverflow);
}

function setWrapRef(el: Element | ComponentPublicInstance | null) {
    wrapObserver?.disconnect();
    wrapObserver = null;

    if (!el || !(el instanceof HTMLElement)) {
        return;
    }

    wrapObserver = new ResizeObserver(() => measureOverflow());
    wrapObserver.observe(el);
}

watch(items, () => nextTick(measureOverflow));

onBeforeUnmount(() => wrapObserver?.disconnect());
</script>

<template>
    <div
        v-if="items.length || editable"
        :ref="setWrapRef"
        class="trip-meta-wrap"
    >
        <div
            :ref="setRowRef"
            class="trip-meta-row"
            :class="{
                'trip-meta-row--editable': editable,
                'trip-meta-row--clamped': !expanded,
            }"
            :role="editable ? 'button' : undefined"
            :tabindex="editable ? 0 : undefined"
            @click="editable && emit('edit')"
            @keydown.enter="editable && emit('edit')"
        >
            <span
                v-for="(item, index) in items"
                :key="item.label"
                class="trip-meta-entry"
                :aria-label="`${item.label}: ${item.value}`"
            >
                <component
                    :is="item.icon"
                    :size="13"
                    class="trip-meta-icon"
                    aria-hidden="true"
                />
                <span aria-hidden="true"
                    >{{ item.value
                    }}<template v-if="index < items.length - 1">
                        ·</template
                    ></span
                >
            </span>
            <button
                v-if="editable"
                type="button"
                class="trip-meta-edit-btn"
                @click.stop="emit('edit')"
            >
                <Pencil :size="12" /> {{ t('itinerary.meta.edit') }}
            </button>
        </div>
        <button
            v-if="hasOverflow || expanded"
            type="button"
            class="trip-meta-toggle"
            @click="expanded = !expanded"
        >
            {{
                expanded
                    ? t('itinerary.showLess')
                    : t('itinerary.meta.showMore')
            }}
        </button>
    </div>
</template>
