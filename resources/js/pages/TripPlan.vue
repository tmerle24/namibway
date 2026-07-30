<script setup lang="ts">
import '../../css/kaia-home.css';
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CurrencySwitcher from '@/components/CurrencySwitcher.vue';
import ItineraryLineItem from '@/components/home/ItineraryLineItem.vue';
import SaveShareBar from '@/components/home/SaveShareBar.vue';
import TripMap from '@/components/home/TripMap.vue';
import { formatPrice } from '@/lib/currency';
import { fetchRegionCoords } from '@/lib/kaia-client';
import type { RegionCoords } from '@/lib/kaia-client';
import type { ItineraryPlan, ItineraryVariant } from '@/lib/kaia-types';
import logoDark from '../../images/logo-dark.png';

const props = defineProps<{
    plan: ItineraryPlan;
    title: string | null;
    token: string;
    shareUrl: string;
}>();

const page = usePage();
const auth = computed(() => page.props.auth as { user: { id: number } | null });
const isLoggedIn = computed(() => auth.value.user !== null);

function loginUrl(): string {
    return `/login/start?redirect=/trip/${props.token}`;
}

const { t } = useI18n();

const regionCoords = ref<Record<string, RegionCoords>>({});

onMounted(async () => {
    regionCoords.value = await fetchRegionCoords();
});

function estimatedLabel(variant: ItineraryVariant): string | null {
    let amount = 0;
    let hasAnyPrice = false;

    for (const day of variant.days) {
        for (const item of [day.accommodation, day.activity, day.restaurant]) {
            if (item?.price_from) {
                amount += Number(item.price_from);
                hasAnyPrice = true;
            }
        }
    }

    if (variant.vehicle?.price_from) {
        amount += Number(variant.vehicle.price_from) * variant.days.length;
        hasAnyPrice = true;
    }

    if (!hasAnyPrice) {
        return null;
    }

    return t('itinerary.estimated', { price: formatPrice(amount) });
}
</script>

<template>
    <div class="kaia-page trip-plan-page">
        <div class="trip-plan-content">
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

            <div v-if="!isLoggedIn" class="login-banner">
                <div class="login-banner-text">
                    <strong>{{ t('tripPlan.loginCta.title') }}</strong>
                    <span>{{ t('tripPlan.loginCta.subtitle') }}</span>
                </div>
                <div class="login-banner-actions">
                    <a :href="loginUrl()" class="login-btn">{{
                        t('tripPlan.loginCta.login')
                    }}</a>
                    <a href="/register" class="register-btn">{{
                        t('tripPlan.loginCta.register')
                    }}</a>
                </div>
            </div>

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

                    <div
                        v-for="day in variant.days"
                        :key="day.day"
                        class="day-row"
                    >
                        <div class="day-col">
                            <div class="day-num">{{ day.day }}</div>
                            <div v-if="day.date" class="day-date">
                                {{ day.date }}
                            </div>
                        </div>
                        <div class="day-detail">
                            <div class="day-location-label">
                                {{ day.location }}
                            </div>
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
    </div>
</template>

<style scoped>
.trip-plan-content {
    max-width: 1040px;
    margin: 0 auto;
    padding: 0 24px 40px;
}

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

.day-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 44px;
    flex-shrink: 0;
    padding-top: 1px;
}

.day-date {
    font-size: 9px;
    color: #a09080;
    text-align: center;
    margin-top: 2px;
    line-height: 1.2;
    white-space: nowrap;
}

.day-location-label {
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 6px;
    color: #2c2521;
}

.login-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: #faf8f5;
    border: 1px solid var(--sand-dark, #d6c9b5);
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 28px;
    flex-wrap: wrap;
}

.login-banner-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 13px;
    color: #5b5346;
}

.login-banner-text strong {
    font-size: 14px;
    color: #2c2521;
}

.login-banner-actions {
    display: flex;
    gap: 10px;
    flex-shrink: 0;
}

.login-btn {
    display: inline-block;
    padding: 7px 18px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    background: #c0533a;
    color: #fff;
    text-decoration: none;
}

.login-btn:hover {
    background: #a8432c;
}

.register-btn {
    display: inline-block;
    padding: 7px 18px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    background: transparent;
    color: #c0533a;
    border: 1px solid #c0533a;
    text-decoration: none;
}

.register-btn:hover {
    background: #fdf0ed;
}

@media print {
    .trip-plan-content {
        padding: 0;
    }
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
