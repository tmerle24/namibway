<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();

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
    <select :value="page.props.locale" class="locale-switcher" @change="switchLocale">
        <option v-for="(label, code) in page.props.availableLocales" :key="code" :value="code">
            {{ label }}
        </option>
    </select>
</template>
