<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatPrice } from '@/lib/currency';
import type { ItineraryListingRef } from '@/lib/kaia-types';
import KebabMenu from './KebabMenu.vue';
import ListingPreviewModal from './ListingPreviewModal.vue';

const props = defineProps<{
    keypath: string;
    itemRef: ItineraryListingRef | null | undefined;
    readonly?: boolean;
    // Skips the i18n-t sentence wrapper ("Stay: {value}") — used inside the
    // timeline's labeled boxes (e.g. the accommodation box), where the box's
    // own caption already conveys what `keypath` would otherwise spell out.
    // Activity/restaurant entries have their own row component
    // (ItineraryEntryRow.vue), not this one.
    hideLabel?: boolean;
}>();

const emit = defineEmits<{
    (e: 'remove'): void;
    (e: 'swap'): void;
    (e: 'add'): void;
}>();

const { t } = useI18n();

// Listing names open a preview modal rather than navigating away — on a
// phone, leaving the page loses the traveler's place in the itinerary.
const previewSlug = ref<string | null>(null);

const menuItems = computed(() => {
    const items: { key: string; label: string; danger?: boolean }[] = [];

    if (props.itemRef?.id) {
        items.push({ key: 'swap', label: t('itinerary.change') });
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
    <div v-if="hideLabel" class="line-item line-item--bare">
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
                <span
                    v-if="formatPrice(props.itemRef.price_from)"
                    class="item-price"
                    >{{ formatPrice(props.itemRef.price_from)
                    }}<template v-if="props.itemRef.type === 'vehicle'"
                        >/{{ t('itinerary.perDay') }}</template
                    ></span
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
    </div>
    <i18n-t v-else :keypath="keypath" tag="div" class="line-item">
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
                    <span
                        v-if="formatPrice(props.itemRef.price_from)"
                        class="item-price"
                        >{{ formatPrice(props.itemRef.price_from) }}</span
                    >
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
