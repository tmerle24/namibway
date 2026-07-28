<script setup lang="ts">
import '../../css/kaia-home.css';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ItineraryLineItem from '@/components/home/ItineraryLineItem.vue';
import SaveShareBar from '@/components/home/SaveShareBar.vue';
import TripMap from '@/components/home/TripMap.vue';
import { fetchRegionCoords } from '@/lib/kaia-client';
import type { RegionCoords } from '@/lib/kaia-client';
import type { ItineraryPlan, ItineraryVariant } from '@/lib/kaia-types';
import logoDark from '../../images/logo-dark.png';

defineProps<{
    plan: ItineraryPlan;
    title: string | null;
    token: string;
    shareUrl: string;
}>();

const { t } = useI18n();

const regionCoords = ref<Record<string, RegionCoords>>({});

onMounted(async () => {
    regionCoords.value = await fetchRegionCoords();
});

function estimatedLabel(variant: ItineraryVariant): string | null {
    let amount = 0;
    let currency: string | null = null;

    for (const day of variant.days) {
        for (const item of [day.accommodation, day.activity, day.restaurant]) {
            if (item?.price_from) {
                amount += Number(item.price_from);
                currency ??= item.price_currency;
            }
        }
    }

    if (variant.vehicle?.price_from) {
        amount += Number(variant.vehicle.price_from) * variant.days.length;
        currency ??= variant.vehicle.price_currency;
    }

    if (!currency) {
        return null;
    }

    return t('itinerary.estimated', {
        amount: Math.round(amount).toLocaleString(),
        currency,
    });
}
</script>

<template>
    <div class="kaia-page trip-plan-page">
        <header class="trip-plan-header">
            <a href="/" class="trip-plan-logo">
                <img
                    :src="logoDark"
                    alt="NamibWay"
                    class="footer-logo"
                    style="height: 28px"
                />
            </a>
            <div class="trip-plan-meta">
                <div class="eyebrow">{{ t('itinerary.eyebrow') }}</div>
                <h1>{{ title || t('itinerary.title') }}</h1>
                <p v-if="plan.trip_summary" class="trip-summary">
                    {{ plan.trip_summary }}
                </p>
            </div>
        </header>

        <div class="variants">
            <div
                v-for="(variant, variantIndex) in plan.variants"
                :key="variant.name"
                class="variant-card"
            >
                <h3>{{ variant.name }}</h3>
                <div v-if="estimatedLabel(variant)" class="variant-price">
                    {{ estimatedLabel(variant) }}
                </div>

                <TripMap
                    :map-id="`trip-map-${variantIndex}`"
                    :variant="variant"
                    :region-coords="regionCoords"
                />

                <template v-if="variant.vehicle">
                    <ItineraryLineItem
                        keypath="itinerary.vehicle"
                        :item-ref="variant.vehicle"
                        :readonly="true"
                    />
                </template>

                <div v-for="day in variant.days" :key="day.day" class="day-row">
                    <div class="day-num">{{ day.day }}</div>
                    <div class="day-detail">
                        <div class="day-location-label">{{ day.location }}</div>
                        <ItineraryLineItem
                            v-if="day.accommodation"
                            keypath="itinerary.stay"
                            :item-ref="day.accommodation"
                            :readonly="true"
                        />
                        <ItineraryLineItem
                            v-if="day.activity"
                            keypath="itinerary.activity"
                            :item-ref="day.activity"
                            :readonly="true"
                        />
                        <ItineraryLineItem
                            v-if="day.restaurant"
                            keypath="itinerary.dinner"
                            :item-ref="day.restaurant"
                            :readonly="true"
                        />
                    </div>
                </div>

                <SaveShareBar
                    :plan="{
                        trip_summary: plan.trip_summary,
                        variants: [variant],
                    }"
                    :token="token"
                />
            </div>
        </div>

        <footer>
            <img :src="logoDark" alt="NamibWay" class="footer-logo" />
            <p>{{ t('footer.tagline') }}</p>
        </footer>
    </div>
</template>

<style scoped>
.trip-plan-header {
    padding: 28px 0 20px;
    border-bottom: 1px solid var(--sand-dark, #d6c9b5);
    margin-bottom: 32px;
}

.trip-plan-logo {
    display: inline-block;
    margin-bottom: 16px;
}

.trip-plan-meta h1 {
    font-size: 26px;
    margin-bottom: 6px;
}

.trip-summary {
    color: #5b5346;
    font-size: 14px;
    max-width: 600px;
    line-height: 1.5;
}

.day-location-label {
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 6px;
    color: #2c2521;
}

@media print {
    .trip-plan-header {
        padding-top: 0;
    }
    .save-share-bar {
        display: none !important;
    }
    .trip-map-wrapper {
        display: none !important;
    }
    .variant-card {
        border: none;
        padding: 0;
        break-inside: avoid;
    }
    footer {
        display: none;
    }
}
</style>
