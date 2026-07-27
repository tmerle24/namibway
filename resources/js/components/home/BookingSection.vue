<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ItineraryVariant } from '@/lib/kaia-types';

const props = defineProps<{
    variant: ItineraryVariant;
}>();

const { t } = useI18n();

type QueueStatus = 'waiting' | 'sending' | 'confirmed';

interface QueueItem {
    property: string;
    status: QueueStatus;
}

const queue = ref<QueueItem[]>([]);

async function runQueue(variant: ItineraryVariant) {
    const names = variant.days
        .map((d) => d.accommodation?.name)
        .filter((name): name is string => Boolean(name));
    const properties = [...new Set(names)];
    queue.value = properties.map((property) => ({
        property,
        status: 'waiting' as QueueStatus,
    }));

    for (const item of queue.value) {
        item.status = 'sending';
        await new Promise((resolve) => setTimeout(resolve, 1400));
        item.status = 'confirmed';
    }
}

watch(
    () => props.variant,
    (variant) => runQueue(variant),
    { immediate: true },
);
</script>

<template>
    <section id="booking-section">
        <div class="section-head">
            <div class="eyebrow">{{ t('booking.eyebrow') }}</div>
            <h2>{{ t('booking.title') }}</h2>
            <p>{{ t('booking.subtitle') }}</p>
        </div>
        <div class="queue">
            <div v-for="item in queue" :key="item.property" class="queue-item">
                <span>{{ item.property }}</span>
                <span :class="['status-pill', `status-${item.status}`]">{{
                    t(`booking.${item.status}`)
                }}</span>
            </div>
        </div>
        <div class="governance-note">
            {{ t('booking.note') }}
        </div>
    </section>
</template>
