<script setup lang="ts">
import { ref, watch } from 'vue';
import type { ItineraryVariant } from '@/lib/kaia-demo';

const props = defineProps<{
    variant: ItineraryVariant;
}>();

type QueueStatus = 'waiting' | 'sending' | 'confirmed';

interface QueueItem {
    property: string;
    status: QueueStatus;
}

const queue = ref<QueueItem[]>([]);

const statusLabel: Record<QueueStatus, string> = {
    waiting: 'Waiting',
    sending: 'Sending request…',
    confirmed: 'Confirmed',
};

async function runQueue(variant: ItineraryVariant) {
    const properties = [...new Set(variant.days.map((d) => d.accommodation).filter(Boolean))];
    queue.value = properties.map((property) => ({ property, status: 'waiting' as QueueStatus }));

    for (const item of queue.value) {
        item.status = 'sending';
        await new Promise((resolve) => setTimeout(resolve, 1400));
        item.status = 'confirmed';
    }
}

watch(() => props.variant, (variant) => runQueue(variant), { immediate: true });
</script>

<template>
    <section id="booking-section">
        <div class="section-head">
            <div class="eyebrow">Booking assistant</div>
            <h2>Turning the plan into confirmed reality</h2>
            <p>Requests go out one at a time — never a flood to every partner at once.</p>
        </div>
        <div class="queue">
            <div v-for="item in queue" :key="item.property" class="queue-item">
                <span>{{ item.property }}</span>
                <span :class="['status-pill', `status-${item.status}`]">{{ statusLabel[item.status] }}</span>
            </div>
        </div>
        <div class="governance-note">
            Only one request is active at a time — this protects partners from being flooded with speculative
            enquiries, and mirrors how a good travel agency would work a booking.
        </div>
    </section>
</template>
