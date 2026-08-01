<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();

function switchCurrency(event: Event) {
    const currency = (event.target as HTMLSelectElement).value;
    router.post('/currency', { currency }, { preserveScroll: true, preserveState: false });
}
</script>

<template>
    <select
        :value="page.props.currency"
        class="locale-switcher"
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
</template>
