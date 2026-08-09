<script setup lang="ts">
import '../../css/kaia-home.css';
import { nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminBar from '@/components/AdminBar.vue';
import AfterSalesSection from '@/components/home/AfterSalesSection.vue';
import BookingSection from '@/components/home/BookingSection.vue';
import ExploreSection from '@/components/home/ExploreSection.vue';
import GuestDetailsForm from '@/components/home/GuestDetailsForm.vue';
import HeroChat from '@/components/home/HeroChat.vue';
import HowItWorks from '@/components/home/HowItWorks.vue';
import ItinerarySection from '@/components/home/ItinerarySection.vue';
import type { MobileSection } from '@/components/home/MobileFooterNav.vue';
import MobileFooterNav from '@/components/home/MobileFooterNav.vue';
import TopDestinations from '@/components/home/TopDestinations.vue';
import SiteFooter from '@/components/SiteFooter.vue';
import { hasSavedExploreScroll } from '@/lib/explore-scroll';
import { createTrip, loadPlan, savePlan } from '@/lib/kaia-client';
import type {
    GuestDetails,
    ItineraryPlan,
    ItineraryVariant,
    SearchIntent,
} from '@/lib/kaia-types';

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
const tripToken = ref<string | null>(null);
// Only set when arriving on an existing ?trip= token; a freshly generated plan
// has no server version until ItinerarySection's first autosave mints one.
const tripVersion = ref<number | null>(null);
const tripShareToken = ref<string | null>(null);
// A ?trip= link can be the read-only one someone was sent.
const tripCanEdit = ref(true);
// Whether the plan behind ?trip= is already in this traveler's account.
const tripOwned = ref(false);
const searchIntent = ref<SearchIntent | null>(null);
const bookingVariant = ref<ItineraryVariant | null>(null);
const bookingActive = ref(false);
const bookingLoading = ref(false);
const bookingError = ref<string | null>(null);
const bookingTripId = ref<number | null>(null);
const guestName = ref<string | null>(null);
const guestEmail = ref<string | null>(null);
const mobileSection = ref<MobileSection>('kaia');
// Full-screen mobile chat mode, entered once the user taps into the Kaia
// panel/input (see HeroChat.vue's `chat-active` emit and kaia-home.css's
// `chat-fullscreen` rules). Reset whenever the user leaves the Kaia tab, so
// switching back later starts fresh at the hero view instead of skipping
// straight to full-screen chat.
const chatFullscreen = ref(false);

watch(mobileSection, (section) => {
    if (section !== 'kaia') {
        chatFullscreen.value = false;
    }
});

async function scrollTo(id: string) {
    await nextTick();
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Keeps the address bar in sync with the in-progress plan (ItinerarySection
// mints/updates the token) so reloading or revisiting the URL restores it —
// replaceState, not a real navigation, since the page itself never changes.
watch(tripToken, (token) => {
    const url = new URL(window.location.href);

    if (token) {
        url.searchParams.set('trip', token);
    } else {
        url.searchParams.delete('trip');
    }

    window.history.replaceState(window.history.state, '', url);
});

async function onSearchIntent(
    intent: SearchIntent,
    opts?: { skipScroll?: boolean },
) {
    searchIntent.value = intent;
    // On mobile, the Explore section is only in the DOM's visible flow when
    // this tab is active (see kaia-home.css's data-mobile-section rules) —
    // without this, scrollTo('explore-section') below is scrolling to a
    // display:none element and nothing visibly happens. No-op on desktop,
    // where mobileSection never gates anything.
    mobileSection.value = 'explore';

    if (!opts?.skipScroll) {
        await scrollTo('explore-section');
    }
}

const LISTING_TYPES = ['accommodation', 'activity', 'restaurant', 'vehicle'];

onMounted(async () => {
    const tripParam = new URLSearchParams(window.location.search).get('trip');

    if (tripParam) {
        try {
            const loaded = await loadPlan(tripParam);
            plan.value = loaded.plan;
            tripVersion.value = loaded.version;
            tripShareToken.value = loaded.shareToken;
            tripCanEdit.value = loaded.canEdit;
            tripOwned.value = loaded.owned;
            tripToken.value = tripParam;
        } catch {
            // Unknown/expired token — fall through to a normal fresh visit.
        }
    }

    // Arriving from MobileFooterNav on another page (e.g. a listing detail
    // page), which links back here with the intended mobile tab pre-selected
    // since there's no local section state to toggle from over there.
    const mobileSectionParam = new URLSearchParams(window.location.search).get(
        'mobileSection',
    );
    const MOBILE_SECTIONS: MobileSection[] = [
        'kaia',
        'discover',
        'explore',
        'support',
    ];

    if (MOBILE_SECTIONS.includes(mobileSectionParam as MobileSection)) {
        mobileSection.value = mobileSectionParam as MobileSection;
    }

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
    tripToken.value = null;
    tripVersion.value = null;
    tripShareToken.value = null;
    tripCanEdit.value = true;
    tripOwned.value = false;
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
        // Booking is made against the plan's own token — that's how the server
        // knows this is its creator (TripController::store). The autosave has
        // normally minted one long before the guest form is filled in; persist
        // it here if that save happened to fail, rather than dead-ending the
        // traveler on a rule they can't see.
        let token = tripToken.value;

        if (!token) {
            const saved = await savePlan(plan.value);
            token = saved.token;
            tripToken.value = saved.token;
            tripShareToken.value = saved.shareToken;
            tripOwned.value = saved.owned;
        }

        const result = await createTrip(
            details,
            bookingVariant.value.name,
            plan.value,
            bookingVariant.value.days,
            token,
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
    <div
        class="kaia-page"
        :class="{ 'has-plan': plan, 'chat-fullscreen': chatFullscreen }"
        :data-mobile-section="mobileSection"
    >
        <AdminBar />
        <HeroChat
            @plan-ready="onPlanReady"
            @search-intent="onSearchIntent"
            @chat-active="chatFullscreen = $event"
        />
        <ItinerarySection
            v-if="plan"
            :plan="plan"
            :token="tripToken"
            :version="tripVersion"
            :share-token="tripShareToken"
            :can-edit="tripCanEdit"
            :owned="tripOwned"
            @book="onBook"
            @update:token="tripToken = $event"
        />
        <TopDestinations
            :destinations="destinations"
            @select="(region) => onSearchIntent({ region })"
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

        <SiteFooter />

        <MobileFooterNav local v-model:active="mobileSection" />
    </div>
</template>
