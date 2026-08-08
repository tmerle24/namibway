<script setup lang="ts">
import { ArrowLeftRight, BedDouble } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatPrice } from '@/lib/currency';
import type { ItineraryListingRef, RoomOption } from '@/lib/kaia-types';
import KebabMenu from './KebabMenu.vue';
import ListingPreviewModal from './ListingPreviewModal.vue';

const props = defineProps<{
    stay: ItineraryListingRef | null | undefined;
    // Pre-formatted check-in -> check-out range for the whole stage, from the
    // parent's stageDateRangeLabel() — this card shows one stay, not one day.
    dateRangeLabel: string;
    nights: number;
    roomSelection?: RoomOption | null;
    readonly?: boolean;
}>();

const emit = defineEmits<{
    (e: 'swap'): void;
    (e: 'remove'): void;
    (e: 'add'): void;
    (e: 'choose-room'): void;
    (e: 'clear-room'): void;
}>();

const { t } = useI18n();

// Same reasoning as ItineraryLineItem: opening the listing in a modal keeps
// the traveler's place in the itinerary instead of navigating away.
const previewSlug = ref<string | null>(null);

// The stay is the single most expensive line in the plan, so it always states
// a price — a listing without one says so rather than showing nothing, which
// reads as "free" or as a rendering bug.
const priceLabel = computed(
    () => formatPrice(props.stay?.price_from) ?? t('itinerary.priceOnRequest'),
);

// "Doppelzimmer · 1 – 4 Jan 2027 (3 Nächte)" — the room drops out until one is
// picked, the nights count until the plan has real dates.
const stayMetaLabel = computed(() => {
    const parts: string[] = [];

    if (props.roomSelection) {
        parts.push(props.roomSelection.name);
    }

    if (props.dateRangeLabel) {
        parts.push(
            props.nights > 0
                ? `${props.dateRangeLabel} (${t('itinerary.meta.nights', props.nights)})`
                : props.dateRangeLabel,
        );
    }

    return parts.join(' · ');
});

// One line of "why this one" under the name: the rating if the listing has
// been reviewed, then whatever the short description opens with. Both are
// frequently missing on scraped listings, so either half stands alone.
const ratingLabel = computed(() => {
    if (props.stay?.rating === null || props.stay?.rating === undefined) {
        return null;
    }

    const rating = `★ ${props.stay.rating.toFixed(1)}`;

    return props.stay.rating_count
        ? `${rating} (${props.stay.rating_count})`
        : rating;
});

const menuItems = computed(() => {
    const items: { key: string; label: string; danger?: boolean }[] = [];

    if (props.stay?.id) {
        items.push({ key: 'swap', label: t('itinerary.changeStay') });
    }

    items.push({ key: 'delete', label: t('itinerary.remove'), danger: true });

    return items;
});

function onMenuSelect(key: string) {
    if (key === 'swap') {
        emit('swap');
    } else if (key === 'delete') {
        emit('remove');
    }
}
</script>

<template>
    <div class="day-card-box stay-card">
        <div class="day-card-box-label">{{ t('itinerary.stayLabel') }}</div>

        <template v-if="stay">
            <div class="stay-card-main">
                <img
                    v-if="stay.image"
                    :src="stay.image"
                    alt=""
                    class="stay-card-thumb"
                />
                <div class="stay-card-body">
                    <div class="stay-card-title-row">
                        <button
                            v-if="stay.slug"
                            type="button"
                            class="line-item-link stay-card-name"
                            @click="previewSlug = stay.slug"
                        >
                            {{ stay.name }}
                        </button>
                        <span v-else class="stay-card-name">{{
                            stay.name
                        }}</span>
                        <span class="item-price stay-card-price"
                            >{{ priceLabel
                            }}<template v-if="stay.price_from"
                                >/{{ t('itinerary.perNight') }}</template
                            ></span
                        >
                    </div>

                    <div v-if="stayMetaLabel" class="stay-card-meta">
                        {{ stayMetaLabel }}
                    </div>

                    <div
                        v-if="ratingLabel || stay.short_description"
                        class="stay-card-details"
                    >
                        <span v-if="ratingLabel" class="stay-card-rating">{{
                            ratingLabel
                        }}</span>
                        <span v-if="ratingLabel && stay.short_description">
                            ·
                        </span>
                        <span v-if="stay.short_description">{{
                            stay.short_description
                        }}</span>
                    </div>
                </div>

                <KebabMenu
                    v-if="!readonly"
                    :items="menuItems"
                    :label="t('itinerary.moreOptions')"
                    @select="onMenuSelect"
                />
            </div>

            <div v-if="!readonly" class="stay-card-actions">
                <button
                    type="button"
                    class="stay-action-btn"
                    @click="emit('choose-room')"
                >
                    <BedDouble :size="13" />
                    {{
                        roomSelection
                            ? t('itinerary.changeRoom')
                            : t('itinerary.chooseRoom')
                    }}
                </button>
                <button
                    type="button"
                    class="stay-action-btn"
                    @click="emit('swap')"
                >
                    <ArrowLeftRight :size="13" />
                    {{ t('itinerary.changeStay') }}
                </button>
                <button
                    v-if="roomSelection"
                    type="button"
                    class="remove-btn stay-card-clear-room"
                    :aria-label="t('itinerary.remove')"
                    @click="emit('clear-room')"
                >
                    ×
                </button>
            </div>
        </template>

        <div v-else class="stay-card-empty">
            —
            <button
                v-if="!readonly"
                type="button"
                class="add-item-btn"
                @click="emit('add')"
            >
                + {{ t('itinerary.add') }}
            </button>
        </div>
    </div>

    <ListingPreviewModal
        v-if="previewSlug"
        :slug="previewSlug"
        @close="previewSlug = null"
    />
</template>
