<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Binoculars,
    CircleUserRound,
    Compass,
    LifeBuoy,
    Search,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useIsApp } from '@/composables/useIsApp';
import { dashboard, login } from '@/routes';

export type MobileSection = 'kaia' | 'discover' | 'explore' | 'support';

defineProps<{
    active: MobileSection;
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
</script>

<template>
    <nav v-if="!isApp" class="mobile-footer-nav" aria-label="Mobile navigation">
        <button
            type="button"
            class="mobile-footer-nav-item"
            :class="{ active: active === 'explore' }"
            @click="emit('update:active', 'explore')"
        >
            <Search :size="20" />
            <span>{{ t('nav.mobileSearch') }}</span>
        </button>
        <button
            type="button"
            class="mobile-footer-nav-item"
            :class="{ active: active === 'discover' }"
            @click="emit('update:active', 'discover')"
        >
            <Binoculars :size="20" />
            <span>{{ t('nav.mobileDiscover') }}</span>
        </button>
        <button
            type="button"
            class="mobile-footer-nav-compass"
            :class="{ active: active === 'kaia' }"
            :aria-label="t('nav.mobileKaia')"
            @click="emit('update:active', 'kaia')"
        >
            <Compass :size="26" />
        </button>
        <button
            type="button"
            class="mobile-footer-nav-item"
            :class="{ active: active === 'support' }"
            @click="emit('update:active', 'support')"
        >
            <LifeBuoy :size="20" />
            <span>{{ t('nav.mobileSupport') }}</span>
        </button>
        <Link :href="accountHref" class="mobile-footer-nav-item">
            <CircleUserRound :size="20" />
            <span>{{ t('nav.mobileAccount') }}</span>
        </Link>
    </nav>
</template>
