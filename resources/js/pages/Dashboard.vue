<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

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

    <div class="flex flex-col gap-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold text-foreground">
                {{ t('dashboard.title') }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('dashboard.subtitle') }}
            </p>
        </div>

        <div
            v-if="plans.length === 0"
            class="rounded-xl border border-border p-10 text-center text-sm text-muted-foreground"
        >
            {{ t('dashboard.empty') }}
            <br />
            <Link
                href="/"
                class="mt-3 inline-block text-primary underline-offset-4 hover:underline"
            >
                {{ t('dashboard.startPlanning') }}
            </Link>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="px-4 py-3 text-left font-medium text-muted-foreground"
                        >
                            {{ t('dashboard.planTitle') }}
                        </th>
                        <th
                            class="px-4 py-3 text-left font-medium text-muted-foreground"
                        >
                            {{ t('dashboard.created') }}
                        </th>
                        <th
                            class="px-4 py-3 text-right font-medium text-muted-foreground"
                        >
                            {{ t('dashboard.actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr
                        v-for="plan in plans"
                        :key="plan.id"
                        class="hover:bg-muted/30"
                    >
                        <td class="px-4 py-3 font-medium">
                            {{ plan.title || t('dashboard.untitled') }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ formatDate(plan.created_at) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a
                                :href="`/trip/${plan.token}`"
                                class="mr-3 text-primary underline-offset-4 hover:underline"
                            >
                                {{ t('dashboard.view') }}
                            </a>
                            <a
                                :href="`/trip/${plan.token}/pdf`"
                                class="text-muted-foreground underline-offset-4 hover:underline"
                            >
                                PDF
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
