<script setup lang="ts">
import '../../css/kaia-home.css';
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AfterSalesSection from '@/components/home/AfterSalesSection.vue';
import BookingSection from '@/components/home/BookingSection.vue';
import ExploreSection from '@/components/home/ExploreSection.vue';
import GuestDetailsForm from '@/components/home/GuestDetailsForm.vue';
import HeroChat from '@/components/home/HeroChat.vue';
import HowItWorks from '@/components/home/HowItWorks.vue';
import ItinerarySection from '@/components/home/ItinerarySection.vue';
import { createTrip } from '@/lib/kaia-client';
import type {
    GuestDetails,
    ItineraryPlan,
    ItineraryVariant,
} from '@/lib/kaia-types';
import logoDark from '../../images/logo-dark.png';

interface Listing {
    id: number;
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    name: string;
    slug: string;
    description: string | null;
    image: string | null;
    region: string | null;
    latitude: number | null;
    longitude: number | null;
    price_from: string | null;
    price_currency: string;
    rating: number | null;
    rating_count: number | null;
}

interface Region {
    name: string;
    slug: string;
    blurb: string | null;
    image: string | null;
    listing_region: string;
}

const { t } = useI18n();

defineProps<{
    listings: Listing[];
    regions: Region[];
}>();

const plan = ref<ItineraryPlan | null>(null);
const bookingVariant = ref<ItineraryVariant | null>(null);
const bookingActive = ref(false);
const bookingLoading = ref(false);
const bookingError = ref<string | null>(null);
const bookingTripId = ref<number | null>(null);
const guestName = ref<string | null>(null);
const guestEmail = ref<string | null>(null);

async function scrollTo(id: string) {
    await nextTick();
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function onPlanReady(newPlan: ItineraryPlan) {
    plan.value = newPlan;
    bookingVariant.value = null;
    bookingActive.value = false;
    await scrollTo('itinerary-section');
}

async function onBook(variant: ItineraryVariant) {
    bookingVariant.value = variant;
    bookingActive.value = false;
    bookingError.value = null;
    await scrollTo('guest-form-section');
}

async function onGuestSubmit(details: GuestDetails) {
    if (!bookingVariant.value || !plan.value) {
        return;
    }

    bookingLoading.value = true;
    bookingError.value = null;

    try {
        const result = await createTrip(
            details,
            bookingVariant.value.name,
            plan.value,
            bookingVariant.value.days,
        );
        bookingTripId.value = result.trip_id;
        guestName.value = details.name;
        guestEmail.value = details.email;
        bookingActive.value = true;
        await scrollTo('booking-section');
    } catch (e) {
        bookingError.value =
            e instanceof Error ? e.message : t('errors.bookingFailed');
    } finally {
        bookingLoading.value = false;
    }
}
</script>

<template>
    <div class="kaia-page">
        <HeroChat @plan-ready="onPlanReady" />
        <ItinerarySection v-if="plan" :plan="plan" @book="onBook" />
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
        <AfterSalesSection
            :guest-name="guestName"
            :guest-email="guestEmail"
            :trip-id="bookingTripId"
        />
        <HowItWorks />
        <ExploreSection :listings="listings" :regions="regions" />

        <footer>
            <img :src="logoDark" alt="NamibWay" class="footer-logo" />
            <p>{{ t('footer.tagline') }}</p>
        </footer>
    </div>
</template>
