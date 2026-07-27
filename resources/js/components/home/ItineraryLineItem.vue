<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { ItineraryListingRef } from '@/lib/kaia-types';
import { show } from '@/routes/listings';

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
                <template v-if="!props.readonly">
                    <button v-if="props.itemRef.id" type="button" class="swap-btn" :aria-label="t('itinerary.swap')" @click="$emit('swap')">⇄</button>
                    <button type="button" class="remove-btn" :aria-label="t('itinerary.remove')" @click="$emit('remove')">×</button>
                </template>
            </template>
            <template v-else>
                —
                <button v-if="!props.readonly" type="button" class="add-item-btn" @click="$emit('add')">+ {{ t('itinerary.add') }}</button>
            </template>
        </template>
    </i18n-t>
</template>
