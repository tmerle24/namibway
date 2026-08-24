<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { legal, terms } from '@/routes';
import logoDark from '../../images/logo-dark.png';

const { t } = useI18n();
</script>

<template>
    <footer>
        <img :src="logoDark" alt="NamibWay" class="footer-logo" />
        <p>{{ t('footer.tagline') }}</p>
        <!-- The legal notice has to be reachable from whatever page somebody
             is standing on, which is the whole reason it lives in the footer
             rather than in a menu. -->
        <nav class="footer-links">
            <Link :href="legal()">{{ t('footer.legal') }}</Link>
            <Link :href="terms()">{{ t('footer.terms') }}</Link>
        </nav>
    </footer>
</template>
