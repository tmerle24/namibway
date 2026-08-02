<script setup lang="ts">
import '../../css/kaia-home.css';
import { nextTick, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminBar from '@/components/AdminBar.vue';
import AfterSalesSection from '@/components/home/AfterSalesSection.vue';
import BookingSection from '@/components/home/BookingSection.vue';
import ExploreSection from '@/components/home/ExploreSection.vue';
import GuestDetailsForm from '@/components/home/GuestDetailsForm.vue';
import HeroChat from '@/components/home/HeroChat.vue';
import HowItWorks from '@/components/home/HowItWorks.vue';
import ItinerarySection from '@/components/home/ItinerarySection.vue';
import TopDestinations from '@/components/home/TopDestinations.vue';
import { hasSavedExploreScroll } from '@/lib/explore-scroll';
import { createTrip } from '@/lib/kaia-client';
import type {
    GuestDetails,
    ItineraryPlan,
    ItineraryVariant,
    SearchIntent,
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
    city: string | null;
    latitude: number | null;
    longitude: number | null;
    price_from: string | null;
    price_currency: string;
    rating: number | null;
    rating_count: number | null;
}

interface Destination {
    name: string;
    slug: string;
    blurb: string | null;
    image: string | null;
    region_name: string;
}

const { t } = useI18n();

defineProps<{
    listings: Listing[];
    destinations: Destination[];
    featuredPick: Listing | null;
}>();

const plan = ref<ItineraryPlan | null>(null);
const searchIntent = ref<SearchIntent | null>(null);
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

async function onSearchIntent(
    intent: SearchIntent,
    opts?: { skipScroll?: boolean },
) {
    searchIntent.value = intent;

    if (!opts?.skipScroll) {
        await scrollTo('explore-section');
    }
}

const LISTING_TYPES = ['accommodation', 'activity', 'restaurant', 'vehicle'];

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const type = params.get('type');
    const region = params.get('region');
    const city = params.get('city');
    const budget = params.get('budget');
    const keyword = params.get('keyword');
    const minRating = params.get('min_rating');
    const sort = params.get('sort');

    if (
        !type &&
        !region &&
        !city &&
        !budget &&
        !keyword &&
        !minRating &&
        !sort
    ) {
        return;
    }

    // Returning from a listing's "back to overview" link: ExploreSection
    // restores the exact scroll position once its results are ready, so
    // skip the smooth scroll-into-view we'd otherwise do here.
    onSearchIntent(
        {
            type: LISTING_TYPES.includes(type ?? '')
                ? (type as SearchIntent['type'])
                : undefined,
            region: region ?? undefined,
            city: city ?? undefined,
            budget: (budget as SearchIntent['budget']) ?? undefined,
            keyword: keyword ?? undefined,
            min_rating: minRating ?? undefined,
            sort: sort ?? undefined,
        },
        { skipScroll: hasSavedExploreScroll() },
    );
});

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
        <AdminBar />
        <HeroChat @plan-ready="onPlanReady" @search-intent="onSearchIntent" />
        <TopDestinations
            :destinations="destinations"
            @select="(region) => onSearchIntent({ region })"
        />
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
        <HowItWorks />
        <ExploreSection
            :listings="listings"
            :destinations="destinations"
            :featured-pick="featuredPick"
            :trigger-search="searchIntent"
        />
        <AfterSalesSection
            :guest-name="guestName"
            :guest-email="guestEmail"
            :trip-id="bookingTripId"
        />

        <footer>
            <img :src="logoDark" alt="NamibWay" class="footer-logo" />
            <p>{{ t('footer.tagline') }}</p>
        </footer>
    </div>
</template>
