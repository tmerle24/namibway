<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Optional deep link to this page's record in the Filament admin, e.g.
// `/admin/listings/${listing.id}/edit`. Omit on pages with no single
// underlying record (e.g. the homepage).
const props = defineProps<{
    editUrl?: string;
}>();

const page = usePage();

// auth.user is typed as non-nullable in resources/js/types/auth.ts (written for the
// authenticated settings/dashboard pages that type was built for), but on these public
// pages the vast majority of visitors are guests — Laravel's HandleInertiaRequests shares
// $request->user(), which is genuinely null for them. Optional-chain regardless of what
// the type claims, or every anonymous visitor hits a runtime crash here.
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin));

const ADMIN_LINKS = [
    { label: 'Dashboard', href: '/admin' },
    { label: 'Listings', href: '/admin/listings' },
    { label: 'Data Enrichment', href: '/admin/enrichment' },
    { label: 'Partners', href: '/admin/partners' },
    { label: 'Inquiries', href: '/admin/inquiries' },
];
</script>

<template>
    <div v-if="isAdmin" class="admin-bar">
        <span class="admin-bar-badge">Admin</span>
        <a
            v-if="props.editUrl"
            :href="props.editUrl"
            class="admin-bar-link admin-bar-link--highlight"
            >Edit this listing</a
        >
        <a
            v-for="link in ADMIN_LINKS"
            :key="link.href"
            :href="link.href"
            class="admin-bar-link"
            >{{ link.label }}</a
        >
    </div>
</template>

<style scoped>
.admin-bar {
    position: sticky;
    top: 0;
    z-index: 200;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 6px 16px;
    background: #111827;
    border-bottom: 1px solid #1f2937;
    font-size: 13px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}

.admin-bar::-webkit-scrollbar {
    display: none;
}

.admin-bar-badge {
    flex-shrink: 0;
    color: #f59e0b;
    font-weight: 700;
    white-space: nowrap;
}

.admin-bar-link {
    flex-shrink: 0;
    color: #9ca3af;
    text-decoration: none;
    white-space: nowrap;
}

.admin-bar-link:hover {
    color: #e5e7eb;
    text-decoration: underline;
}

.admin-bar-link--highlight {
    color: #e5e7eb;
    text-decoration: underline;
}
</style>
