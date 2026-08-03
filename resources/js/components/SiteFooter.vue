<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import logoDark from '../../images/logo-dark.png';

const { t } = useI18n();
</script>

<template>
    <footer>
        <img :src="logoDark" alt="NamibWay" class="footer-logo" />
        <p>{{ t('footer.tagline') }}</p>
    </footer>
</template>
