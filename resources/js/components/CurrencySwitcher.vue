<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { SYMBOLS } from '@/lib/currency';

const page = usePage();
const { t } = useI18n();

const glyph = computed(
    () => SYMBOLS[page.props.currency as string] ?? page.props.currency,
);

function switchCurrency(event: Event) {
    const currency = (event.target as HTMLSelectElement).value;
    router.post(
        '/currency',
        { currency },
        { preserveScroll: true, preserveState: false },
    );
}
</script>

<template>
    <span class="icon-switcher">
        <span class="icon-switcher-glyph" aria-hidden="true">{{ glyph }}</span>
        <select
            :value="page.props.currency"
            class="icon-switcher-native"
            :aria-label="t('nav.currency')"
            @change="switchCurrency"
        >
            <option
                v-for="(label, code) in page.props.availableCurrencies"
                :key="code"
                :value="code"
            >
                {{ label }}
            </option>
        </select>
    </span>
</template>
