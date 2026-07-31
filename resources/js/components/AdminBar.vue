<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Menu, X } from '@lucide/vue';
import { computed, ref } from 'vue';

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

const menuOpen = ref(false);

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
        <div class="admin-bar-row">
            <span class="admin-bar-badge">Admin</span>

            <!-- Wide viewports: flat, horizontally-scrollable link row. -->
            <div class="admin-bar-links">
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

            <!-- Narrow viewports: collapses to a burger toggle instead — the flat row
                 used to wrap/overflow off-screen with no way to reach the later links. -->
            <button
                type="button"
                class="admin-bar-burger"
                :aria-expanded="menuOpen"
                aria-label="Toggle admin menu"
                @click="menuOpen = !menuOpen"
            >
                <X v-if="menuOpen" :size="18" />
                <Menu v-else :size="18" />
            </button>
        </div>

        <div v-if="menuOpen" class="admin-bar-dropdown">
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
    </div>
</template>

<style scoped>
.admin-bar {
    position: sticky;
    top: 0;
    z-index: 200;
    background: #111827;
    border-bottom: 1px solid #1f2937;
    font-size: 13px;
}

.admin-bar-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 6px 16px;
}

.admin-bar-badge {
    flex-shrink: 0;
    color: #f59e0b;
    font-weight: 700;
    white-space: nowrap;
}

.admin-bar-links {
    display: flex;
    align-items: center;
    gap: 16px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}

.admin-bar-links::-webkit-scrollbar {
    display: none;
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

.admin-bar-burger {
    display: none;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    color: #e5e7eb;
    padding: 4px;
    cursor: pointer;
}

.admin-bar-dropdown {
    display: none;
    flex-direction: column;
    gap: 10px;
    padding: 4px 16px 14px;
    border-top: 1px solid #1f2937;
}

@media (max-width: 640px) {
    .admin-bar-links {
        display: none;
    }

    .admin-bar-burger {
        display: flex;
    }

    .admin-bar-dropdown {
        display: flex;
    }
}
</style>
