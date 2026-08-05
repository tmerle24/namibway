<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import type { RegionCoords } from '@/lib/kaia-client';
import type { ItineraryVariant } from '@/lib/kaia-types';
import TripMap from './TripMap.vue';
import type { DrivingLeg } from './TripMap.vue';

const props = defineProps<{
    variant: ItineraryVariant;
    regionCoords: Record<string, RegionCoords>;
    mapId: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'driving-legs', legs: DrivingLeg[]): void;
}>();

const { t } = useI18n();

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div
        class="map-modal-backdrop"
        role="dialog"
        aria-modal="true"
        @click.self="emit('close')"
    >
        <div class="map-modal-box">
            <button
                class="map-modal-close"
                :aria-label="t('listingPreview.close')"
                @click="emit('close')"
            >
                ×
            </button>
            <TripMap
                :map-id="props.mapId"
                :variant="props.variant"
                :region-coords="props.regionCoords"
                @driving-legs="(legs) => emit('driving-legs', legs)"
            />
        </div>
    </div>
</template>

<style scoped>
.map-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(16, 26, 48, 0.7);
    z-index: 270;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.map-modal-box {
    position: relative;
    width: 100%;
    max-width: 460px;
    max-height: 90vh;
    overflow-y: auto;
}

.map-modal-close {
    position: absolute;
    top: -14px;
    right: -6px;
    z-index: 2;
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--night, #1b2a4a);
    color: #fff;
    border: 3px solid var(--paper, #fbf8f1);
    border-radius: 999px;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(16, 26, 48, 0.35);
}

.map-modal-close:hover {
    background: var(--night-deep, #101a30);
}
</style>
