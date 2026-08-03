<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, CalendarCheck, Map } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import '../../css/kaia-home.css';
import AdminBar from '@/components/AdminBar.vue';
import SiteFooter from '@/components/SiteFooter.vue';
import SiteHeader from '@/components/SiteHeader.vue';
import { dashboard } from '@/routes';
import { edit as editListing } from '@/routes/listings';

type DashboardTab = 'trips' | 'listings' | 'bookings';

interface SavedPlan {
    id: number;
    token: string;
    title: string | null;
    created_at: string;
}

interface OwnerListing {
    id: number;
    slug: string;
    name: string;
    is_published: boolean;
    updated_at: string;
}

interface OwnerBooking {
    id: number;
    listing_name: string | null;
    listing_slug: string | null;
    guest_name: string;
    status: string;
    check_in: string | null;
    check_out: string | null;
    travel_dates: string | null;
    created_at: string;
}

const props = defineProps<{
    isOwner: boolean;
    activeTab: DashboardTab;
    plans: SavedPlan[];
    listings: OwnerListing[];
    bookings: OwnerBooking[];
}>();

const { t } = useI18n();

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function bookingDates(booking: OwnerBooking): string {
    if (booking.check_in && booking.check_out) {
        return `${formatDate(booking.check_in)} – ${formatDate(booking.check_out)}`;
    }

    return booking.travel_dates ?? '—';
}

const showTripsTab = computed(() => !props.isOwner || props.plans.length > 0);
</script>

<template>
    <Head :title="t('nav.dashboard')" />

    <div class="kaia-page">
        <AdminBar />
        <SiteHeader />

        <section class="dashboard-section">
            <nav class="dashboard-sidebar">
                <Link
                    v-if="isOwner"
                    :href="dashboard({ query: { tab: 'listings' } })"
                    class="dashboard-nav-link"
                    :class="{ active: activeTab === 'listings' }"
                >
                    <Building2 :size="16" />
                    {{ t('dashboard.nav.listings') }}
                </Link>
                <Link
                    v-if="isOwner"
                    :href="dashboard({ query: { tab: 'bookings' } })"
                    class="dashboard-nav-link"
                    :class="{ active: activeTab === 'bookings' }"
                >
                    <CalendarCheck :size="16" />
                    {{ t('dashboard.nav.bookings') }}
                </Link>
                <Link
                    v-if="showTripsTab"
                    :href="dashboard({ query: { tab: 'trips' } })"
                    class="dashboard-nav-link"
                    :class="{ active: activeTab === 'trips' }"
                >
                    <Map :size="16" />
                    {{ t('dashboard.nav.trips') }}
                </Link>
            </nav>

            <div class="dashboard-content">
                <template v-if="activeTab === 'listings'">
                    <h1>{{ t('dashboard.listings.title') }}</h1>
                    <p class="dashboard-subtitle">
                        {{ t('dashboard.listings.subtitle') }}
                    </p>

                    <div v-if="listings.length === 0" class="dashboard-empty">
                        {{ t('dashboard.listings.empty') }}
                    </div>

                    <div v-else class="dashboard-table-wrap">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>{{ t('dashboard.listings.name') }}</th>
                                    <th>
                                        {{ t('dashboard.listings.status') }}
                                    </th>
                                    <th>
                                        {{ t('dashboard.listings.updated') }}
                                    </th>
                                    <th>
                                        {{ t('dashboard.listings.actions') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="listing in listings"
                                    :key="listing.id"
                                >
                                    <td>{{ listing.name }}</td>
                                    <td>
                                        <span
                                            class="dashboard-badge"
                                            :class="
                                                listing.is_published
                                                    ? 'dashboard-badge--published'
                                                    : 'dashboard-badge--unpublished'
                                            "
                                        >
                                            {{
                                                listing.is_published
                                                    ? t(
                                                          'dashboard.listings.published',
                                                      )
                                                    : t(
                                                          'dashboard.listings.unpublished',
                                                      )
                                            }}
                                        </span>
                                    </td>
                                    <td>
                                        {{ formatDate(listing.updated_at) }}
                                    </td>
                                    <td class="dashboard-table-actions">
                                        <Link :href="editListing(listing.slug)">
                                            {{ t('dashboard.listings.edit') }}
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <template v-else-if="activeTab === 'bookings'">
                    <h1>{{ t('dashboard.bookings.title') }}</h1>
                    <p class="dashboard-subtitle">
                        {{ t('dashboard.bookings.subtitle') }}
                    </p>

                    <div v-if="bookings.length === 0" class="dashboard-empty">
                        {{ t('dashboard.bookings.empty') }}
                    </div>

                    <div v-else class="dashboard-table-wrap">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>
                                        {{ t('dashboard.bookings.listing') }}
                                    </th>
                                    <th>{{ t('dashboard.bookings.guest') }}</th>
                                    <th>{{ t('dashboard.bookings.dates') }}</th>
                                    <th>
                                        {{ t('dashboard.bookings.status') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="booking in bookings"
                                    :key="booking.id"
                                >
                                    <td>{{ booking.listing_name ?? '—' }}</td>
                                    <td>{{ booking.guest_name }}</td>
                                    <td>{{ bookingDates(booking) }}</td>
                                    <td>
                                        <span
                                            class="dashboard-status-pill"
                                            :class="`dashboard-status-pill--${booking.status}`"
                                        >
                                            {{
                                                t(
                                                    `booking.status.${booking.status}`,
                                                )
                                            }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <template v-else>
                    <h1>{{ t('dashboard.title') }}</h1>
                    <p class="dashboard-subtitle">
                        {{ t('dashboard.subtitle') }}
                    </p>

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
                                        {{
                                            plan.title ||
                                            t('dashboard.untitled')
                                        }}
                                    </td>
                                    <td>{{ formatDate(plan.created_at) }}</td>
                                    <td class="dashboard-table-actions">
                                        <a :href="`/trip/${plan.token}`">{{
                                            t('dashboard.view')
                                        }}</a>
                                        <a :href="`/trip/${plan.token}/pdf`"
                                            >PDF</a
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </section>

        <SiteFooter />
    </div>
</template>
