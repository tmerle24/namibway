<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ImageLightbox from '@/components/ImageLightbox.vue';
import { formatPrice } from '@/lib/currency';
import { fetchAvailability } from '@/lib/kaia-client';
import type { AvailabilitySlot, AvailabilityUnit } from '@/lib/kaia-client';
import type { RoomOption } from '@/lib/kaia-types';
import { onImageError, thumbAttrs } from '@/lib/media';

const props = defineProps<{
    slug: string | null;
    adults: number;
    children: number;
    checkIn: string | null;
    checkOut?: string | null;
    images?: string[];
    title?: string;
}>();

const emit = defineEmits<{
    (e: 'select', option: RoomOption): void;
}>();

const { t } = useI18n();

const units = ref<AvailabilityUnit[]>([]);
const periods = ref(1);
const loading = ref(false);
const failed = ref(false);
const lightboxImages = ref<string[]>([]);
const lightboxIndex = ref<number | null>(null);

async function load() {
    if (!props.slug || !props.checkIn) {
        units.value = [];

        return;
    }

    loading.value = true;
    failed.value = false;

    try {
        const result = await fetchAvailability(props.slug, {
            checkIn: props.checkIn,
            checkOut: props.checkOut ?? undefined,
            adults: props.adults,
            children: props.children,
        });
        units.value = result.units;
        periods.value = result.periods;
    } catch (e) {
        console.warn('Failed to load availability:', e);
        failed.value = true;
        units.value = [];
    } finally {
        loading.value = false;
    }
}

watch(
    () => [
        props.slug,
        props.checkIn,
        props.checkOut,
        props.adults,
        props.children,
    ],
    load,
    { immediate: true },
);

function capacityLabel(unit: AvailabilityUnit): string {
    const parts = [t('itinerary.meta.adults', unit.max_adults)];

    if (unit.max_children > 0) {
        parts.push(t('itinerary.meta.children', unit.max_children));
    }

    return parts.join(', ');
}

function imagesFor(unit: AvailabilityUnit): string[] {
    return unit.gallery.length ? unit.gallery : (props.images ?? []);
}

function openLightbox(unit: AvailabilityUnit): void {
    const images = imagesFor(unit);

    if (!images.length) {
        return;
    }

    lightboxImages.value = images;
    lightboxIndex.value = 0;
}

function chargeLabel(unit: AvailabilityUnit): string {
    const added = unit.charges.filter((c) => !c.included);

    if (!added.length) {
        return t('itinerary.roomChargesIncluded', {
            named: { names: unit.charges.map((c) => c.name).join(', ') },
        });
    }

    return t('itinerary.roomChargesAdded', {
        named: {
            amount: formatPrice(
                String(added.reduce((sum, c) => sum + c.amount, 0)),
            ),
            names: added.map((c) => c.name).join(', '),
        },
    });
}

function chooseUnit(unit: AvailabilityUnit): void {
    emit('select', {
        code: unit.code,
        name: unit.name,
        capacity: capacityLabel(unit),
        price_per_night: (unit.total ?? 0) / periods.value,
        currency: unit.currency,
        total_price: unit.total,
        total_payable: unit.total_payable,
        units_left: unit.units_left,
        image: unit.gallery[0] ?? null,
    });
}

function chooseSlot(unit: AvailabilityUnit, slot: AvailabilitySlot): void {
    emit('select', {
        code: unit.code,
        name: `${unit.name} · ${slot.label}`,
        capacity: capacityLabel(unit),
        price_per_night: slot.total,
        currency: unit.currency,
        total_price: slot.total,
        total_payable: slot.total_payable,
        units_left: slot.units_left,
        image: unit.gallery[0] ?? null,
    });
}
</script>

<template>
    <div class="room-options-panel">
        <div class="alternatives-title">
            {{ title ?? t('itinerary.chooseRoom') }}
        </div>

        <div v-if="loading" class="room-options-note">
            {{ t('itinerary.roomsLoading') }}
        </div>

        <div v-else-if="failed" class="room-options-note">
            {{ t('itinerary.roomsFailed') }}
        </div>

        <div v-else-if="!units.length" class="room-options-note">
            {{ t('itinerary.roomsUnavailable') }}
        </div>

        <template v-else>
            <template v-for="unit in units" :key="unit.code">
                <!-- Date-range unit (accommodation, vehicle) -->
                <template v-if="unit.slots.length === 0">
                    <button
                        type="button"
                        class="alternative-item room-option-item"
                        @click="chooseUnit(unit)"
                    >
                        <span
                            v-if="imagesFor(unit).length"
                            class="room-option-thumb"
                            :class="{
                                'room-option-thumb--stand-in':
                                    !unit.gallery.length,
                            }"
                            :title="
                                unit.gallery.length
                                    ? t('itinerary.viewPhotos')
                                    : t('itinerary.roomPhotoStandIn')
                            "
                            @click.stop="openLightbox(unit)"
                        >
                            <img
                                v-bind="thumbAttrs(imagesFor(unit)[0], 36)"
                                :alt="unit.name"
                                width="36"
                                height="36"
                                loading="lazy"
                                decoding="async"
                                @error="onImageError($event, 'accommodation')"
                            />
                        </span>
                        <span class="alt-name">{{ unit.name }}</span>
                        <span class="room-option-capacity">{{
                            capacityLabel(unit)
                        }}</span>
                        <span class="alt-price">
                            {{
                                formatPrice(String((unit.total ?? 0) / periods))
                            }}
                            <span class="room-option-per-night"
                                >/{{ t('itinerary.perNight') }}</span
                            >
                        </span>
                        <span class="alt-use-btn">{{
                            t('itinerary.useThis')
                        }}</span>
                        <span v-if="periods > 1" class="room-option-total">
                            {{
                                t('itinerary.roomTotalForNights', periods, {
                                    named: {
                                        total: formatPrice(
                                            String(unit.total_payable),
                                        ),
                                        count: periods,
                                    },
                                })
                            }}
                        </span>
                        <span
                            v-if="unit.charges.length"
                            class="room-option-charges"
                        >
                            {{ chargeLabel(unit) }}
                        </span>
                        <span
                            v-if="
                                unit.units_left !== null && unit.units_left <= 3
                            "
                            class="room-option-scarce"
                        >
                            {{ t('itinerary.roomsLeft', unit.units_left) }}
                        </span>
                    </button>
                </template>

                <!-- Slot-based unit (activity) -->
                <template v-else>
                    <div class="slot-unit-header">
                        <span class="alt-name">{{ unit.name }}</span>
                        <span class="room-option-capacity">{{
                            capacityLabel(unit)
                        }}</span>
                    </div>
                    <button
                        v-for="slot in unit.slots"
                        :key="slot.starts_at"
                        type="button"
                        class="alternative-item room-option-item"
                        @click="chooseSlot(unit, slot)"
                    >
                        <span class="slot-time">{{ slot.starts_at }}</span>
                        <span class="alt-name">{{ slot.label }}</span>
                        <span class="room-option-capacity"
                            >{{ slot.duration_minutes }} min</span
                        >
                        <span class="alt-price">
                            {{ formatPrice(String(slot.total)) }}
                            <span class="room-option-per-night"
                                >/{{ t('itinerary.perSeat') }}</span
                            >
                        </span>
                        <span class="alt-use-btn">{{
                            t('itinerary.useThis')
                        }}</span>
                        <span
                            v-if="slot.units_left <= 3"
                            class="room-option-scarce"
                        >
                            {{ t('itinerary.seatsLeft', slot.units_left) }}
                        </span>
                    </button>
                </template>
            </template>
        </template>

        <ImageLightbox
            v-if="lightboxIndex !== null && lightboxImages.length"
            :images="lightboxImages"
            :index="lightboxIndex"
            @update:index="lightboxIndex = $event"
            @close="lightboxIndex = null"
        />
    </div>
</template>

<style scoped>
.room-options-panel {
    margin: 4px 0 6px 0;
    border-left: 2px solid var(--sand-dark);
    padding-left: 10px;
}
.room-options-note {
    font-size: 11.5px;
    color: #8a7f68;
    margin: -2px 0 6px;
}
.room-option-item {
    flex-wrap: wrap;
}
.room-option-capacity {
    font-size: 11.5px;
    color: #8a7f68;
}
.room-option-per-night {
    font-weight: 400;
    font-size: 11px;
    color: #8a7f68;
}
.room-option-total {
    flex-basis: 100%;
    font-size: 11.5px;
    color: #8a7f68;
}
.room-option-charges {
    flex-basis: 100%;
    font-size: 11px;
    color: #8a7f68;
}
.room-option-scarce {
    flex-basis: 100%;
    font-size: 11px;
    font-weight: 600;
    color: var(--rust-dark);
}
.room-option-thumb {
    display: block;
    width: 36px;
    height: 36px;
    border-radius: 6px;
    overflow: hidden;
    flex-shrink: 0;
    cursor: zoom-in;
}
.room-option-thumb--stand-in img {
    opacity: 0.75;
}
.room-option-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.slot-unit-header {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 4px 0 2px;
    font-size: 12px;
    font-weight: 600;
}
.slot-time {
    font-size: 11px;
    font-weight: 600;
    color: #8a7f68;
    flex-shrink: 0;
}
</style>
