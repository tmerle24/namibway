<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { ItineraryListingRef } from '@/lib/kaia-types';
import { show } from '@/routes/listings';

const props = defineProps<{
    keypath: string;
    itemRef: ItineraryListingRef | null | undefined;
}>();

defineEmits<{
    (e: 'remove'): void;
}>();

const { t } = useI18n();

function refUrl(ref: ItineraryListingRef): string {
    return show({ listing: ref.slug ?? '' }).url;
}

function formatPrice(price: string): string {
    return Number(price).toLocaleString();
}
</script>

<template>
    <i18n-t :keypath="keypath" tag="div" class="line-item">
        <template #value>
            <template v-if="props.itemRef">
                <a v-if="props.itemRef.slug" :href="refUrl(props.itemRef)" target="_blank" rel="noopener noreferrer">{{ props.itemRef.name }}</a>
                <template v-else>{{ props.itemRef.name }}</template>
                <span v-if="props.itemRef.price_from" class="item-price">{{ props.itemRef.price_currency }} {{ formatPrice(props.itemRef.price_from) }}</span>
                <button type="button" class="remove-btn" :aria-label="t('itinerary.remove')" @click="$emit('remove')">×</button>
            </template>
            <template v-else>—</template>
        </template>
    </i18n-t>
</template>
