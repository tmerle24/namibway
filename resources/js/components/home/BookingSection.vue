<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { TripInquiryStatus } from '@/lib/kaia-client';
import { fetchTripInquiries } from '@/lib/kaia-client';
import type { ItineraryVariant } from '@/lib/kaia-types';

const props = defineProps<{
    variant: ItineraryVariant;
    tripId: number;
}>();

const { t } = useI18n();

const inquiries = ref<TripInquiryStatus[]>([]);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const TERMINAL_STATUSES = new Set(['confirmed', 'cancelled', 'failed']);

function statusClass(status: string): string {
    if (status === 'confirmed') {
        return 'status-confirmed';
    }

    if (status === 'cancelled' || status === 'failed') {
        return 'status-failed';
    }

    if (status === 'on_request' || status === 'nwr_pending') {
        return 'status-waiting';
    }

    return 'status-sending';
}

function allTerminal(items: TripInquiryStatus[]): boolean {
    return (
        items.length > 0 && items.every((i) => TERMINAL_STATUSES.has(i.status))
    );
}

async function poll() {
    try {
        const result = await fetchTripInquiries(props.tripId);

        inquiries.value = result;

        if (!allTerminal(result)) {
            pollTimer = setTimeout(poll, 4000);
        }
    } catch {
        pollTimer = setTimeout(poll, 8000);
    }
}

watch(
    () => props.tripId,
    () => {
        if (pollTimer !== null) {
            clearTimeout(pollTimer);
        }

        inquiries.value = [];
        poll();
    },
    { immediate: true },
);

onUnmounted(() => {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
    }
});
</script>

<template>
    <section id="booking-section">
        <div class="section-head">
            <div class="eyebrow">{{ t('booking.eyebrow') }}</div>
            <h2>{{ t('booking.title') }}</h2>
            <p>{{ t('booking.subtitle') }}</p>
        </div>
        <div class="queue">
            <div
                v-for="item in inquiries"
                :key="item.listing_name"
                class="queue-item"
            >
                <span>{{ item.listing_name }}</span>
                <span :class="['status-pill', statusClass(item.status)]">{{
                    item.label
                }}</span>
            </div>
            <div
                v-if="inquiries.length === 0"
                class="queue-item queue-item--loading"
            >
                <span>{{ t('booking.loadingStatus') }}</span>
            </div>
        </div>
        <div class="governance-note">
            {{ t('booking.note') }}
        </div>
    </section>
</template>
