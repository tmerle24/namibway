<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatPrice } from '@/lib/currency';
import type { ItineraryListingRef } from '@/lib/kaia-types';
import ListingPreviewModal from './ListingPreviewModal.vue';

const props = defineProps<{
    keypath: string;
    itemRef: ItineraryListingRef | null | undefined;
    readonly?: boolean;
}>();

defineEmits<{
    (e: 'remove'): void;
    (e: 'swap'): void;
    (e: 'add'): void;
}>();

const { t } = useI18n();

// Listing names open a preview modal rather than navigating away — on a
// phone, leaving the page loses the traveler's place in the itinerary.
const previewSlug = ref<string | null>(null);
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
                    <span
                        v-if="formatPrice(props.itemRef.price_from)"
                        class="item-price"
                        >{{ formatPrice(props.itemRef.price_from) }}</span
                    >
                    <template v-if="!props.readonly">
                        <button
                            v-if="props.itemRef.id"
                            type="button"
                            class="swap-btn"
                            :aria-label="t('itinerary.swap')"
                            @click="$emit('swap')"
                        >
                            ⇄
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
