<script setup lang="ts">
import '../../css/kaia-home.css';
import { Head, Link } from '@inertiajs/vue3';
import { home } from '@/routes';
import logoDark from '../../images/logo-dark.png';
</script>

<template>
    <Head title="Terms & Conditions" />

    <div class="kaia-page">
        <div class="detail-topbar">
            <Link :href="home()" class="brand"
                ><img :src="logoDark" alt="NamibWay" class="brand-logo"
            /></Link>
            <Link :href="home()" class="detail-back">Back to home</Link>
        </div>

        <div class="terms-page">
            <div class="terms-placeholder-notice">
                Placeholder content — pending NamibWay's own legal review. Not
                final Terms &amp; Conditions.
            </div>

            <h1>NamibWay Terms &amp; Conditions</h1>

            <p>
                By publishing a listing on namibway.com, you confirm that the
                information and photos submitted are accurate and that you have
                the right to publish them — either because they are your own
                property's content, or because you have obtained the necessary
                permission from whoever owns them.
            </p>

            <p>
                NamibWay reserves the right to remove or amend any listing that
                is inaccurate, infringes on someone else's rights, or otherwise
                violates these terms.
            </p>

            <p>
                This page is a placeholder. The complete Terms &amp; Conditions
                — covering commission/booking terms, data protection, liability,
                and dispute resolution — will be published here before NamibWay
                is relied on for real listings at scale.
            </p>
        </div>
    </div>
</template>

<style scoped>
.terms-page {
    max-width: 680px;
    margin: 32px auto 64px;
    padding: 0 24px;
}

.terms-page h1 {
    font-family: 'Fraunces', serif;
    font-size: 28px;
    margin: 0 0 16px;
}

.terms-page p {
    font-size: 14px;
    line-height: 1.7;
    color: #4a4438;
    margin-bottom: 14px;
}

.terms-placeholder-notice {
    background: #fef3c7;
    color: #92400e;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 24px;
}
</style>
