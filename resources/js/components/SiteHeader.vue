<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, LogIn, LogOut, UserPlus } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CurrencySwitcher from '@/components/CurrencySwitcher.vue';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import NavMoreMenu from '@/components/NavMoreMenu.vue';
import { dashboard, home, login, logout, register } from '@/routes';
import logoLight from '../../images/logo-light.png';

/**
 * `overlay` lets the page behind the header show through it — the homepage
 * hero runs to the top of the screen and the header floats on it, until the
 * first scroll turns it back into a solid bar. Only a page that has something
 * worth showing through should ask for it; everywhere else the header is a
 * bar over ordinary content and stays opaque.
 */
const props = defineProps<{
    overlay?: boolean;
}>();

const page = usePage();
const { t } = useI18n();

function handleLogout() {
    router.flushAll();
}

/**
 * How far down the page counts as "scrolling". Small on purpose: the point of
 * the transparent state is the view somebody arrives on and screenshots, not
 * a state to hold on to while they read. The moment they move, the header
 * commits to being a bar — which is also what keeps the logo legible once the
 * part of the photo behind it is no longer the part the hero's scrim darkens.
 */
const SCROLLED_PAST = 24;

const scrolled = ref(false);

function readScroll() {
    // Only ever writes when the boolean actually flips, so the listener costs
    // a comparison per frame rather than a re-render.
    scrolled.value = window.scrollY > SCROLLED_PAST;
}

onMounted(() => {
    if (!props.overlay) {
        return;
    }

    // Not always false to begin with: a reload part-way down the page, or
    // Inertia restoring a scroll position, both arrive already scrolled.
    readScroll();
    window.addEventListener('scroll', readScroll, { passive: true });
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', readScroll);
});

// Published so anything that needs to sit below this sticky header (e.g.
// the trip-plan map column's sticky offset) can read its real rendered
// height instead of a guessed constant — same pattern as AdminBar's
// --admin-bar-height. Reset on unmount: Inertia keeps the document alive
// across page swaps, so a page without SiteHeader (e.g. TripPlan.vue) would
// otherwise inherit a stale height from whichever page rendered last.
//
// Deliberately not what the overlay hero clears itself against: that is a
// layout offset and has to be right on the first paint, before an <img> has
// loaded and before any observer has run. It reads --hero-nav-height, which
// CSS derives from the header's own padding and logo height (kaia-home.css).
const rootEl = ref<HTMLElement | null>(null);
let resizeObserver: ResizeObserver | null = null;

function setSiteHeaderHeightVar(height: number) {
    document.documentElement.style.setProperty(
        '--site-header-height',
        `${height}px`,
    );
}

function publishHeight() {
    setSiteHeaderHeightVar(rootEl.value?.getBoundingClientRect().height ?? 0);
}

function observe(el: HTMLElement | null) {
    resizeObserver?.disconnect();
    resizeObserver = null;

    if (!el) {
        setSiteHeaderHeightVar(0);

        return;
    }

    // border-box: the header's own safe-area padding is part of the height a
    // consumer has to clear, and on iOS that inset changes with orientation.
    resizeObserver = new ResizeObserver(publishHeight);
    resizeObserver.observe(el, { box: 'border-box' });
    publishHeight();
}

// onMounted for the first measurement — the conventional hook for mount-time
// DOM work — and the watch for the case it was written for: the root element
// being replaced rather than the component remounted.
onMounted(() => observe(rootEl.value));
watch(rootEl, (el) => observe(el));

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    setSiteHeaderHeightVar(0);
});
</script>

<template>
    <div
        ref="rootEl"
        class="hero-nav"
        :class="{
            'hero-nav--overlay': overlay,
            'hero-nav--scrolled': overlay && scrolled,
        }"
    >
        <div class="hero-nav-inner">
            <Link :href="home()" class="brand">
                <img :src="logoLight" alt="NamibWay" class="brand-logo" />
            </Link>
            <div class="hero-nav-actions">
                <LocaleSwitcher />
                <NavMoreMenu>
                    <CurrencySwitcher variant="full" />
                    <div class="nav-more-divider"></div>
                    <Link
                        v-if="page.props.auth?.user"
                        :href="dashboard()"
                        class="nav-more-link"
                    >
                        <LayoutDashboard :size="14" />
                        {{ t('nav.dashboard') }}
                    </Link>
                    <template v-else>
                        <Link :href="login()" class="nav-more-link">
                            <LogIn :size="14" />
                            {{ t('nav.login') }}
                        </Link>
                        <Link :href="register()" class="nav-more-link">
                            <UserPlus :size="14" />
                            {{ t('nav.register') }}
                        </Link>
                    </template>
                    <Link
                        v-if="page.props.auth?.user"
                        :href="logout()"
                        as="button"
                        class="nav-more-link nav-more-link--button"
                        @click="handleLogout"
                    >
                        <LogOut :size="14" />
                        {{ t('nav.logout') }}
                    </Link>
                </NavMoreMenu>
            </div>
        </div>
    </div>
</template>
