<script setup lang="ts">
import '../../css/kaia-home.css';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BookingSection from '@/components/home/BookingSection.vue';
import GuestDetailsForm from '@/components/home/GuestDetailsForm.vue';
import ItinerarySection from '@/components/home/ItinerarySection.vue';
import SaveLoginModal from '@/components/home/SaveLoginModal.vue';
import { claimPlan, createTrip } from '@/lib/kaia-client';
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
    // False when this page was opened through a read-only share link.
    canEdit: boolean;
    // Whether this plan is already in the viewer's own account.
    owned: boolean;
    // The plan's read-only token — always the one handed out, even when this
    // page was reached via the edit link.
    shareToken: string | null;
    shareUrl: string;
}>();

const page = usePage();
const auth = computed(() => page.props.auth as { user: { id: number } | null });
const isLoggedIn = computed(() => auth.value.user !== null);

const showLogin = ref(false);
const loginTab = ref<'login' | 'register'>('login');

function openLogin(tab: 'login' | 'register') {
    loginTab.value = tab;
    showLogin.value = true;
}

async function onBannerAuthenticated() {
    showLogin.value = false;

    // Claim the plan for the account that just logged in — that's what "keep
    // this trip plan" means. Only possible from the edit link; a read-only
    // visitor is not its creator, and claimPlan would refuse anyway.
    if (props.canEdit) {
        try {
            await claimPlan(props.token);
        } catch (e) {
            console.warn('Failed to save plan to account:', e);
        }
    }

    // Reload so the banner, `owned` and the shared auth prop all reflect it.
    router.reload();
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
        // The plan's own token — the server books against it to establish that
        // this is the creator and not someone the plan was shared with. Safe to
        // pass props.token: a read-only visitor never gets this far (the book
        // CTA is hidden for them), and the share token would be refused anyway.
        const result = await createTrip(
            details,
            bookingVariant.value.name,
            props.plan,
            bookingVariant.value.days,
            props.token,
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

            <!--
                "Keep this trip plan" is the save-to-account pitch, so it opens
                the same modal the Save button does rather than navigating away
                — and logging in from here claims the plan, which is what the
                banner promises.
            -->
            <div v-if="!isLoggedIn" class="login-banner">
                <div class="login-banner-text">
                    <strong>{{ t('tripPlan.loginCta.title') }}</strong>
                    <span>{{ t('tripPlan.loginCta.subtitle') }}</span>
                </div>
                <div class="login-banner-actions">
                    <button
                        type="button"
                        class="login-btn"
                        @click="openLogin('login')"
                    >
                        {{ t('tripPlan.loginCta.login') }}
                    </button>
                    <button
                        type="button"
                        class="register-btn"
                        @click="openLogin('register')"
                    >
                        {{ t('tripPlan.loginCta.register') }}
                    </button>
                </div>
            </div>

            <SaveLoginModal
                v-if="showLogin"
                :initial-tab="loginTab"
                @close="showLogin = false"
                @authenticated="onBannerAuthenticated"
            />

            <ItinerarySection
                :plan="plan"
                :token="tripToken"
                :version="version"
                :share-token="shareToken"
                :can-edit="canEdit"
                :owned="owned"
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

/* This gutter stacks on top of the itinerary section's own — on a phone the
   day cards need the width more than the page needs a double margin. */
@media (max-width: 640px) {
    .trip-plan-content {
        padding-left: 6px;
        padding-right: 6px;
    }
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
    /* Both of these became <button>s when the banner started opening the
       login modal instead of navigating — a button brings its own border,
       font and cursor defaults that an <a> never had. */
    border: none;
    font-family: inherit;
    cursor: pointer;
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
    font-family: inherit;
    cursor: pointer;
}

.register-btn:hover {
    background: #fdf0ed;
}
</style>
