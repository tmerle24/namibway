<script setup lang="ts">
import '../../css/kaia-home.css';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BookingSection from '@/components/home/BookingSection.vue';
import GuestDetailsForm from '@/components/home/GuestDetailsForm.vue';
import ItinerarySection from '@/components/home/ItinerarySection.vue';
import { createTrip } from '@/lib/kaia-client';
import type {
    GuestDetails,
    ItineraryPlan,
    ItineraryVariant,
} from '@/lib/kaia-types';
import logoDark from '../../images/logo-dark.png';

const props = defineProps<{
    plan: ItineraryPlan;
    title: string | null;
    token: string;
    version: number;
    shareUrl: string;
}>();

const page = usePage();
const auth = computed(() => page.props.auth as { user: { id: number } | null });
const isLoggedIn = computed(() => auth.value.user !== null);

function loginUrl(): string {
    return `/login/start?redirect=/trip/${props.token}`;
}

const { t } = useI18n();

// Same identity ItinerarySection already manages for the live Kaia session
// (see Welcome.vue) — a local ref rather than the raw prop so a future
// token swap (shouldn't normally happen here, since the URL already
// carries a real one) still flows through the same update:token contract.
const tripToken = ref<string | null>(props.token);

const bookingVariant = ref<ItineraryVariant | null>(null);
const bookingActive = ref(false);
const bookingLoading = ref(false);
const bookingError = ref<string | null>(null);
const bookingTripId = ref<number | null>(null);

function scrollTo(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function onBook(variant: ItineraryVariant) {
    bookingVariant.value = variant;
    bookingActive.value = false;
    bookingError.value = null;
    scrollTo('guest-form-section');
}

async function onGuestSubmit(details: GuestDetails) {
    if (!bookingVariant.value) {
        return;
    }

    bookingLoading.value = true;
    bookingError.value = null;

    try {
        const result = await createTrip(
            details,
            bookingVariant.value.name,
            props.plan,
            bookingVariant.value.days,
        );
        bookingTripId.value = result.trip_id;
        bookingActive.value = true;
        scrollTo('booking-section');
    } catch (e) {
        bookingError.value =
            e instanceof Error ? e.message : t('errors.bookingFailed');
    } finally {
        bookingLoading.value = false;
    }
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

            <ItinerarySection
                :plan="plan"
                :token="tripToken"
                :version="version"
                @book="onBook"
                @update:token="tripToken = $event"
            />

            <GuestDetailsForm
                v-if="bookingVariant && !bookingActive"
                :variant="bookingVariant"
                :loading="bookingLoading"
                :error="bookingError"
                @submit="onGuestSubmit"
            />
            <BookingSection
                v-if="bookingActive && bookingVariant && bookingTripId"
                :variant="bookingVariant"
                :trip-id="bookingTripId"
            />

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
</style>
