<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ImageLightbox from '@/components/ImageLightbox.vue';
import { formatPrice } from '@/lib/currency';
import { fetchRoomTypes } from '@/lib/kaia-client';
import type { RoomTypeOffer } from '@/lib/kaia-client';
import type { RoomOption } from '@/lib/kaia-types';
import { onImageError, thumbAttrs } from '@/lib/media';

const props = defineProps<{
    // The accommodation this stay is at. Without it there is nothing to ask
    // about — a listing that was hand-added to the plan has no slug.
    slug: string | null;
    adults: number;
    children: number;
    // The stay's own range as ISO dates. Availability is a property of these
    // dates, not of the listing, so without them there is no answer to give.
    checkIn: string | null;
    checkOut: string | null;
    // The property's photos, used only as a stand-in on rooms that have none
    // of their own.
    images?: string[];
}>();

const emit = defineEmits<{
    (e: 'select', option: RoomOption): void;
}>();

const { t } = useI18n();

const rooms = ref<RoomTypeOffer[]>([]);
const nights = ref(1);
const loading = ref(false);
const failed = ref(false);
const lightboxImages = ref<string[]>([]);
const lightboxIndex = ref<number | null>(null);

const propertyImages = computed(() => props.images ?? []);

// Everything the picker shows now comes from the server: the listing's real
// RoomType rows, their real rate, and how many units are still free for these
// dates. It used to synthesise three tiers client-side by scaling the
// property's price_from — invented numbers next to a Book button.
async function load() {
    if (!props.slug || !props.checkIn || !props.checkOut) {
        rooms.value = [];

        return;
    }

    loading.value = true;
    failed.value = false;

    try {
        const result = await fetchRoomTypes(props.slug, {
            checkIn: props.checkIn,
            checkOut: props.checkOut,
            adults: props.adults,
            children: props.children,
        });
        rooms.value = result.rooms;
        nights.value = result.nights;
    } catch (e) {
        console.warn('Failed to load room types:', e);
        failed.value = true;
        rooms.value = [];
    } finally {
        loading.value = false;
    }
}

// The panel is mounted when it opens (v-if in ItinerarySection), so this fires
// once per opening — and again if the traveler changes the trip's dates or
// party size while it is open.
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

function capacityLabel(room: RoomTypeOffer): string {
    const parts = [t('itinerary.meta.adults', room.max_adults)];

    if (room.max_children > 0) {
        parts.push(t('itinerary.meta.children', room.max_children));
    }

    return parts.join(', ');
}

function imagesFor(room: RoomTypeOffer): string[] {
    return room.gallery.length ? room.gallery : propertyImages.value;
}

function openLightbox(room: RoomTypeOffer) {
    const images = imagesFor(room);

    if (!images.length) {
        return;
    }

    lightboxImages.value = images;
    lightboxIndex.value = 0;
}

function choose(room: RoomTypeOffer) {
    emit('select', {
        code: room.code,
        name: room.name,
        capacity: capacityLabel(room),
        price_per_night: room.price_per_night,
        currency: room.currency,
        total_price: room.total_price,
        units_left: room.units_left,
        image: room.gallery[0] ?? null,
    });
}
</script>

<template>
    <div class="room-options-panel">
        <div class="alternatives-title">{{ t('itinerary.chooseRoom') }}</div>

        <div v-if="loading" class="room-options-note">
            {{ t('itinerary.roomsLoading') }}
        </div>

        <div v-else-if="failed" class="room-options-note">
            {{ t('itinerary.roomsFailed') }}
        </div>

        <!--
            The honest empty state. Most listings hold no room inventory with us
            (scraped listings, partners on their own PMS), and the traveler is
            better served by "the partner will confirm the room" than by three
            made-up tiers.
        -->
        <div v-else-if="!rooms.length" class="room-options-note">
            {{ t('itinerary.roomsUnavailable') }}
        </div>

        <template v-else>
            <button
                v-for="room in rooms"
                :key="room.code"
                type="button"
                class="alternative-item room-option-item"
                @click="choose(room)"
            >
                <span
                    v-if="imagesFor(room).length"
                    class="room-option-thumb"
                    :class="{
                        'room-option-thumb--stand-in': !room.gallery.length,
                    }"
                    :title="
                        room.gallery.length
                            ? t('itinerary.viewPhotos')
                            : t('itinerary.roomPhotoStandIn')
                    "
                    @click.stop="openLightbox(room)"
                >
                    <img
                        v-bind="thumbAttrs(imagesFor(room)[0], 36)"
                        :alt="room.name"
                        width="36"
                        height="36"
                        loading="lazy"
                        decoding="async"
                        @error="onImageError($event, 'accommodation')"
                    />
                </span>
                <span class="alt-name">{{ room.name }}</span>
                <span class="room-option-capacity">{{
                    capacityLabel(room)
                }}</span>
                <span class="alt-price">
                    {{ formatPrice(String(room.price_per_night)) }}
                    <span class="room-option-per-night"
                        >/{{ t('itinerary.perNight') }}</span
                    >
                </span>
                <span class="alt-use-btn">{{ t('itinerary.useThis') }}</span>
                <!--
                    What the stay actually costs, which is the number the
                    traveler is really comparing on. Only worth its own line
                    when it differs from the nightly rate.
                -->
                <span v-if="nights > 1" class="room-option-total">
                    {{
                        t('itinerary.roomTotalForNights', nights, {
                            named: {
                                total: formatPrice(String(room.total_price)),
                                count: nights,
                            },
                        })
                    }}
                </span>
                <!--
                    Only shown when it is genuinely tight. "12 units left" is
                    noise; "last one" is a real fact the traveler wants.
                -->
                <span v-if="room.units_left <= 3" class="room-option-scarce">
                    {{ t('itinerary.roomsLeft', room.units_left) }}
                </span>
            </button>
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
/* A property photo standing in for a room that has none of its own — dimmed
   slightly so it doesn't read as a picture of this particular room. */
.room-option-thumb--stand-in img {
    opacity: 0.75;
}
.room-option-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
</style>
