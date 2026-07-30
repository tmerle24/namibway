<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();

function setCookie(name: string, value: string, days = 365) {
    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
}

function switchCurrency(event: Event) {
    const currency = (event.target as HTMLSelectElement).value;
    setCookie('currency', currency);
    router.reload();
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
