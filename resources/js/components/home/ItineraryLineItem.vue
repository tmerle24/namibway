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
    // timeline's labeled boxes (SCHLAFEN/ERLEBEN/ESSEN), where the box's own
    // caption already conveys what `keypath` would otherwise spell out.
    hideLabel?: boolean;
    // Renders the swap trigger as a labeled pill ("Change") instead of the
    // context menu — used for the vehicle card, where a lone icon button
    // reads as too subtle for the plan's single most prominent swap action.
    swapLabel?: string;
    // Shows a "Add" entry in the item's context menu — for fields that
    // allow more than one entry per day (activities, restaurants); see
    // ItinerarySection.vue's day.activities/day.restaurants arrays.
    allowAdd?: boolean;
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

    if (props.allowAdd) {
        items.push({ key: 'add', label: t('itinerary.add') });
    }

    items.push({ key: 'delete', label: t('itinerary.remove'), danger: true });

    return items;
});

function onMenuSelect(key: string) {
    if (key === 'swap') {
        emit('swap');
    } else if (key === 'add') {
        emit('add');
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
                    >{{ formatPrice(props.itemRef.price_from) }}</span
                >
                <template v-if="!props.readonly">
                    <template v-if="swapLabel">
                        <button
                            v-if="props.itemRef.id"
                            type="button"
                            class="swap-btn swap-btn--labeled"
                            @click="$emit('swap')"
                        >
                            ⇄ {{ swapLabel }}
                        </button>
                        <button
                            type="button"
                            class="remove-btn"
                            :aria-label="t('itinerary.remove')"
                            @click="$emit('remove')"
                        >
                            ×
                        </button>
                    </template>
                    <KebabMenu
                        v-else
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
                    <template v-if="!props.readonly">
                        <template v-if="swapLabel">
                            <button
                                v-if="props.itemRef.id"
                                type="button"
                                class="swap-btn swap-btn--labeled"
                                @click="$emit('swap')"
                            >
                                ⇄ {{ swapLabel }}
                            </button>
                            <button
                                type="button"
                                class="remove-btn"
                                :aria-label="t('itinerary.remove')"
                                @click="$emit('remove')"
                            >
                                ×
                            </button>
                        </template>
                        <KebabMenu
                            v-else
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
