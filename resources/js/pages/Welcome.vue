<script setup lang="ts">
import '../../css/kaia-home.css';
import { nextTick, ref } from 'vue';
import AfterSalesSection from '@/components/home/AfterSalesSection.vue';
import BookingSection from '@/components/home/BookingSection.vue';
import ExploreSection from '@/components/home/ExploreSection.vue';
import HeroChat from '@/components/home/HeroChat.vue';
import ItinerarySection from '@/components/home/ItinerarySection.vue';
import type { ItineraryPlan, ItineraryVariant } from '@/lib/kaia-demo';
import logoDark from '../../images/logo-dark.png';

interface Listing {
    id: number;
    type: 'accommodation' | 'activity' | 'restaurant';
    name: string;
    slug: string;
    description: string | null;
    region: string | null;
    price_from: string | null;
    price_currency: string;
}

defineProps<{
    listings: Listing[];
}>();

const plan = ref<ItineraryPlan | null>(null);
const bookingVariant = ref<ItineraryVariant | null>(null);

async function scrollTo(id: string) {
    await nextTick();
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function onPlanReady(newPlan: ItineraryPlan) {
    plan.value = newPlan;
    await scrollTo('itinerary-section');
}

async function onBook(variant: ItineraryVariant) {
    bookingVariant.value = variant;
    await scrollTo('booking-section');
}
</script>

<template>
    <div class="kaia-page">
        <HeroChat @plan-ready="onPlanReady" />
        <ItinerarySection v-if="plan" :plan="plan" @book="onBook" />
        <BookingSection v-if="bookingVariant" :variant="bookingVariant" />
        <AfterSalesSection />
        <ExploreSection :listings="listings" />

        <footer>
            <img :src="logoDark" alt="NamibWay" class="footer-logo" />
            <p>
                AI-assisted travel planning for Namibia. The chat interview above is a preview flow; live
                AI-generated itineraries and real partner availability are coming as the platform develops.
            </p>
        </footer>
    </div>
</template>
