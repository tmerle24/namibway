<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, CalendarCheck, Map } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import '../../css/kaia-home.css';
import AdminBar from '@/components/AdminBar.vue';
import SiteFooter from '@/components/SiteFooter.vue';
import SiteHeader from '@/components/SiteHeader.vue';
import { dashboard } from '@/routes';

interface SavedPlan {
    id: number;
    token: string;
    title: string | null;
    created_at: string;
}

defineProps<{
    plans: SavedPlan[];
}>();

const { t } = useI18n();

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <Head :title="t('nav.dashboard')" />

    <div class="kaia-page">
        <AdminBar />
        <SiteHeader />

        <section class="dashboard-section">
            <nav class="dashboard-sidebar">
                <Link :href="dashboard()" class="dashboard-nav-link active">
                    <Map :size="16" />
                    {{ t('dashboard.nav.trips') }}
                </Link>
                <span class="dashboard-nav-link disabled">
                    <Building2 :size="16" />
                    {{ t('dashboard.nav.listings') }}
                    <span class="dashboard-nav-badge">{{
                        t('dashboard.nav.comingSoon')
                    }}</span>
                </span>
                <span class="dashboard-nav-link disabled">
                    <CalendarCheck :size="16" />
                    {{ t('dashboard.nav.bookings') }}
                    <span class="dashboard-nav-badge">{{
                        t('dashboard.nav.comingSoon')
                    }}</span>
                </span>
            </nav>

            <div class="dashboard-content">
                <h1>{{ t('dashboard.title') }}</h1>
                <p class="dashboard-subtitle">{{ t('dashboard.subtitle') }}</p>

                <div v-if="plans.length === 0" class="dashboard-empty">
                    {{ t('dashboard.empty') }}
                    <br />
                    <Link href="/">{{ t('dashboard.startPlanning') }}</Link>
                </div>

                <div v-else class="dashboard-table-wrap">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>{{ t('dashboard.planTitle') }}</th>
                                <th>{{ t('dashboard.created') }}</th>
                                <th>{{ t('dashboard.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="plan in plans" :key="plan.id">
                                <td>
                                    {{ plan.title || t('dashboard.untitled') }}
                                </td>
                                <td>{{ formatDate(plan.created_at) }}</td>
                                <td class="dashboard-table-actions">
                                    <a :href="`/trip/${plan.token}`">{{
                                        t('dashboard.view')
                                    }}</a>
                                    <a :href="`/trip/${plan.token}/pdf`">PDF</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <SiteFooter />
    </div>
</template>
