<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Binoculars, CircleUserRound, LifeBuoy, Search } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import NamibWayCompassIcon from '@/components/NamibWayCompassIcon.vue';
import { useIsApp } from '@/composables/useIsApp';
import { dashboard, home, login } from '@/routes';

export type MobileSection = 'kaia' | 'discover' | 'explore' | 'support';

// Welcome.vue's single-page layout: tapping a tab toggles which section is
// shown in place, via `active`/`update:active`. Anywhere else, there's no
// such section to toggle — tapping a tab instead navigates to the homepage
// with that section pre-selected.
const { local = false, active } = defineProps<{
    local?: boolean;
    active?: MobileSection;
}>();

const emit = defineEmits<{
    'update:active': [section: MobileSection];
}>();

const { t } = useI18n();
const page = usePage();
const isApp = useIsApp();
const accountHref = computed(() =>
    page.props.auth?.user ? dashboard() : login(),
);

function homeHref(section: MobileSection) {
    return home({ query: { mobileSection: section } });
}
</script>

<template>
    <nav v-if="!isApp" class="mobile-footer-nav" aria-label="Mobile navigation">
        <button
            v-if="local"
            type="button"
            class="mobile-footer-nav-item"
            :class="{ active: active === 'explore' }"
            @click="emit('update:active', 'explore')"
        >
            <Search :size="20" />
            <span>{{ t('nav.mobileSearch') }}</span>
        </button>
        <Link v-else :href="homeHref('explore')" class="mobile-footer-nav-item">
            <Search :size="20" />
            <span>{{ t('nav.mobileSearch') }}</span>
        </Link>

        <button
            v-if="local"
            type="button"
            class="mobile-footer-nav-item"
            :class="{ active: active === 'discover' }"
            @click="emit('update:active', 'discover')"
        >
            <Binoculars :size="20" />
            <span>{{ t('nav.mobileDiscover') }}</span>
        </button>
        <Link
            v-else
            :href="homeHref('discover')"
            class="mobile-footer-nav-item"
        >
            <Binoculars :size="20" />
            <span>{{ t('nav.mobileDiscover') }}</span>
        </Link>

        <button
            v-if="local"
            type="button"
            class="mobile-footer-nav-compass"
            :class="{ active: active === 'kaia' }"
            :aria-label="t('nav.mobileKaia')"
            @click="emit('update:active', 'kaia')"
        >
            <NamibWayCompassIcon class="mobile-footer-nav-compass-icon" />
        </button>
        <Link
            v-else
            :href="homeHref('kaia')"
            class="mobile-footer-nav-compass"
            :aria-label="t('nav.mobileKaia')"
        >
            <NamibWayCompassIcon class="mobile-footer-nav-compass-icon" />
        </Link>

        <button
            v-if="local"
            type="button"
            class="mobile-footer-nav-item"
            :class="{ active: active === 'support' }"
            @click="emit('update:active', 'support')"
        >
            <LifeBuoy :size="20" />
            <span>{{ t('nav.mobileSupport') }}</span>
        </button>
        <Link v-else :href="homeHref('support')" class="mobile-footer-nav-item">
            <LifeBuoy :size="20" />
            <span>{{ t('nav.mobileSupport') }}</span>
        </Link>

        <Link :href="accountHref" class="mobile-footer-nav-item">
            <CircleUserRound :size="20" />
            <span>{{ t('nav.mobileAccount') }}</span>
        </Link>
    </nav>
</template>
