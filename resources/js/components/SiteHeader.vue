<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, LogIn, LogOut, UserPlus } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CurrencySwitcher from '@/components/CurrencySwitcher.vue';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import NavMoreMenu from '@/components/NavMoreMenu.vue';
import { dashboard, home, login, logout, register } from '@/routes';
import logoLight from '../../images/logo-light.png';

const page = usePage();
const { t } = useI18n();

function handleLogout() {
    router.flushAll();
}

// Published so anything that needs to sit below this sticky header (e.g.
// the trip-plan map column's sticky offset) can read its real rendered
// height instead of a guessed constant — same pattern as AdminBar's
// --admin-bar-height. Reset on unmount: Inertia keeps the document alive
// across page swaps, so a page without SiteHeader (e.g. TripPlan.vue) would
// otherwise inherit a stale height from whichever page rendered last.
const rootEl = ref<HTMLElement | null>(null);
let resizeObserver: ResizeObserver | null = null;

function setSiteHeaderHeightVar(height: number) {
    document.documentElement.style.setProperty(
        '--site-header-height',
        `${height}px`,
    );
}

watch(rootEl, (el) => {
    resizeObserver?.disconnect();
    resizeObserver = null;

    if (!el) {
        setSiteHeaderHeightVar(0);

        return;
    }

    resizeObserver = new ResizeObserver(() => {
        setSiteHeaderHeightVar(el.getBoundingClientRect().height);
    });
    resizeObserver.observe(el);
    setSiteHeaderHeightVar(el.getBoundingClientRect().height);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    setSiteHeaderHeightVar(0);
});
</script>

<template>
    <div ref="rootEl" class="hero-nav">
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
