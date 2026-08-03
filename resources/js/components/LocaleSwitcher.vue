<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { variant = 'icon' } = defineProps<{ variant?: 'icon' | 'full' }>();

const page = usePage();
const { t } = useI18n();

const FLAGS: Record<string, string> = {
    en: '🇬🇧',
    de: '🇩🇪',
    nl: '🇳🇱',
    fr: '🇫🇷',
    es: '🇪🇸',
};

const glyph = computed(() => FLAGS[page.props.locale as string] ?? '🌐');

function setCookie(name: string, value: string, days = 365) {
    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
}

function switchLocale(event: Event) {
    const locale = (event.target as HTMLSelectElement).value;
    setCookie('locale', locale);
    router.reload();
}
</script>

<template>
    <span v-if="variant === 'full'" class="full-switcher">
        <span class="full-switcher-label">{{ t('nav.language') }}</span>
        <select
            :value="page.props.locale"
            class="full-switcher-select"
            :aria-label="t('nav.language')"
            @change="switchLocale"
        >
            <option
                v-for="(label, code) in page.props.availableLocales"
                :key="code"
                :value="code"
            >
                {{ FLAGS[code as string] ?? '' }} {{ label }}
            </option>
        </select>
    </span>
    <span v-else class="icon-switcher">
        <span class="icon-switcher-glyph" aria-hidden="true">{{ glyph }}</span>
        <select
            :value="page.props.locale"
            class="icon-switcher-native"
            :aria-label="t('nav.language')"
            @change="switchLocale"
        >
            <option
                v-for="(label, code) in page.props.availableLocales"
                :key="code"
                :value="code"
            >
                {{ FLAGS[code as string] ?? '' }} {{ label }}
            </option>
        </select>
    </span>
</template>
