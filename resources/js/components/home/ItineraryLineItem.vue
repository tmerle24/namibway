<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ItineraryListingRef } from '@/lib/kaia-types';
import { formatPriceWithUnit } from '@/lib/price-unit';
import KebabMenu from './KebabMenu.vue';
import ListingPreviewModal from './ListingPreviewModal.vue';

const props = defineProps<{
    keypath: string;
    itemRef: ItineraryListingRef | null | undefined;
    readonly?: boolean;
}>();

const emit = defineEmits<{
    (e: 'remove'): void;
    (e: 'swap'): void;
    (e: 'add'): void;
    (e: 'choose-unit'): void;
}>();

const { t } = useI18n();

// Listing names open a preview modal rather than navigating away — on a
// phone, leaving the page loses the traveler's place in the itinerary.
const previewSlug = ref<string | null>(null);

// Every line in the plan states a price. Plenty of scraped listings carry no
// rate yet, and silently omitting the price there reads either as "included"
// or as a bug — "on request" is the honest version and keeps the column from
// looking ragged.
const priceLabel = computed(() =>
    props.itemRef
        ? (formatPriceWithUnit(
              props.itemRef.price_from,
              props.itemRef.price_unit,
              t,
          ) ?? t('itinerary.priceOnRequest'))
        : null,
);

const menuItems = computed(() => {
    const items: { key: string; label: string; danger?: boolean }[] = [];

    if (!props.readonly && props.itemRef?.slug) {
        items.push({ key: 'pick-unit', label: t('itinerary.pickVehicle') });
    }

    if (props.itemRef?.id) {
        items.push({ key: 'swap', label: t('itinerary.change') });
    }

    items.push({ key: 'delete', label: t('itinerary.remove'), danger: true });

    return items;
});

function onMenuSelect(key: string) {
    if (key === 'pick-unit') {
        emit('choose-unit');
    } else if (key === 'swap') {
        emit('swap');
    } else if (key === 'delete') {
        emit('remove');
    }
}
</script>

<template>
    <i18n-t :keypath="keypath" tag="div" class="line-item">
        <template #value>
            <span class="line-item-value">
                <template v-if="props.itemRef">
                    <button
                        v-if="props.itemRef.slug"
                        type="button"
                        class="line-item-link"
                        @click="previewSlug = props.itemRef.slug"
                    >
                        {{ props.itemRef.name }}
                    </button>
                    <template v-else>{{ props.itemRef.name }}</template>
                    <span class="item-price">{{ priceLabel }}</span>
                    <span
                        v-if="
                            props.itemRef.type === 'vehicle' &&
                            props.itemRef.vehicle_category
                        "
                        class="item-vehicle-badge"
                        :class="`item-vehicle-badge--${props.itemRef.vehicle_category}`"
                        >{{
                            t(
                                `vehicle.category.${props.itemRef.vehicle_category}`,
                            )
                        }}</span
                    >
                    <template v-if="!props.readonly">
                        <KebabMenu
                            :items="menuItems"
                            :label="t('itinerary.moreOptions')"
                            @select="onMenuSelect"
                        />
                    </template>
                </template>
                <template v-else>
                    —
                    <button
                        v-if="!props.readonly"
                        type="button"
                        class="add-item-btn"
                        @click="$emit('add')"
                    >
                        + {{ t('itinerary.add') }}
                    </button>
                </template>
            </span>
        </template>
    </i18n-t>

    <ListingPreviewModal
        v-if="previewSlug"
        :slug="previewSlug"
        @close="previewSlug = null"
    />
</template>
