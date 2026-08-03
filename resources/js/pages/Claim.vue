<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import logoDark from '../../images/logo-dark.png';
import logoLight from '../../images/logo-light.png';

interface Props {
    partner: {
        name: string;
        website: string | null;
        claimed_at: string | null;
    };
    listing: {
        name: string;
        type: string;
        region: string | null;
    } | null;
    store_url: string;
    decline_url: string;
}

const props = defineProps<Props>();
const page = usePage();
const auth = computed(
    () => page.props.auth as { user: { name: string } | null },
);
const isLoggedIn = computed(() => auth.value?.user !== null);
const loginUrl = computed(
    () => `/login/start?redirect=${encodeURIComponent(page.url)}`,
);

const form = useForm({});

function claim() {
    form.post(props.store_url);
}

const typeLabel: Record<string, string> = {
    accommodation: 'Accommodation',
    activity: 'Activity',
    restaurant: 'Restaurant',
    vehicle: 'Car Rental',
};
</script>

<template>
    <Head title="Claim your listing — NamibWay" />

    <div
        class="flex min-h-screen items-center justify-center bg-stone-50 px-4 dark:bg-stone-900"
    >
        <div
            class="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm dark:bg-stone-800"
        >
            <div class="mb-8 flex justify-center">
                <img
                    :src="logoDark"
                    alt="NamibWay"
                    class="h-10 w-auto dark:hidden"
                />
                <img
                    :src="logoLight"
                    alt="NamibWay"
                    class="hidden h-10 w-auto dark:block"
                />
            </div>

            <!-- Already claimed -->
            <template v-if="partner.claimed_at">
                <h1
                    class="mb-2 text-center text-2xl font-semibold text-stone-900 dark:text-white"
                >
                    Already claimed
                </h1>
                <p class="text-center text-stone-500 dark:text-stone-400">
                    <strong>{{ partner.name }}</strong> was claimed on
                    {{ partner.claimed_at }}.
                </p>
                <p
                    class="mt-4 text-center text-sm text-stone-500 dark:text-stone-400"
                >
                    If you think this is a mistake, contact us at
                    <a
                        href="mailto:hello@namibway.com"
                        class="text-amber-600 hover:underline"
                        >hello@namibway.com</a
                    >.
                </p>
            </template>

            <!-- Available to claim -->
            <template v-else>
                <h1
                    class="mb-1 text-center text-2xl font-semibold text-stone-900 dark:text-white"
                >
                    Claim your free listing
                </h1>
                <p
                    class="mb-8 text-center text-sm text-stone-500 dark:text-stone-400"
                >
                    Take ownership of this listing on NamibWay and start
                    receiving traveller inquiries.
                </p>

                <!-- Listing card -->
                <div
                    class="mb-8 rounded-xl border border-stone-200 p-4 dark:border-stone-700"
                >
                    <div
                        class="mb-1 text-xs font-medium tracking-wider text-stone-400 uppercase dark:text-stone-500"
                    >
                        {{ typeLabel[listing?.type ?? ''] ?? listing?.type }}
                        <template v-if="listing?.region">
                            · {{ listing.region }}</template
                        >
                    </div>
                    <div
                        class="text-lg font-semibold text-stone-900 dark:text-white"
                    >
                        {{ listing?.name ?? partner.name }}
                    </div>
                    <div
                        v-if="partner.website"
                        class="mt-1 text-sm text-stone-400 dark:text-stone-500"
                    >
                        {{ partner.website }}
                    </div>
                </div>

                <!-- Logged in: claim button -->
                <template v-if="isLoggedIn">
                    <p
                        class="mb-4 text-center text-sm text-stone-600 dark:text-stone-300"
                    >
                        Claiming as <strong>{{ auth.user?.name }}</strong>
                    </p>
                    <button
                        :disabled="form.processing"
                        class="w-full rounded-xl bg-amber-600 px-4 py-3 font-semibold text-white transition hover:bg-amber-700 disabled:opacity-60"
                        @click="claim"
                    >
                        <span v-if="form.processing">Claiming…</span>
                        <span v-else>Confirm — claim this listing</span>
                    </button>
                </template>

                <!-- Guest: prompt to log in / register -->
                <template v-else>
                    <p
                        class="mb-4 text-center text-sm text-stone-600 dark:text-stone-300"
                    >
                        You need a NamibWay account to claim this listing. It's
                        free.
                    </p>
                    <a
                        href="/register"
                        class="mb-3 block w-full rounded-xl bg-amber-600 px-4 py-3 text-center font-semibold text-white transition hover:bg-amber-700"
                    >
                        Create free account
                    </a>
                    <a
                        :href="loginUrl"
                        class="block w-full rounded-xl border border-stone-300 px-4 py-3 text-center font-semibold text-stone-700 transition hover:bg-stone-50 dark:border-stone-600 dark:text-stone-300 dark:hover:bg-stone-700"
                    >
                        Sign in
                    </a>
                </template>

                <p class="mt-6 text-center text-xs text-stone-400">
                    Not your business, or would rather not be listed?
                    <a
                        :href="decline_url"
                        class="text-stone-500 underline hover:text-stone-700 dark:hover:text-stone-300"
                        >Remove this listing</a
                    >
                </p>
            </template>
        </div>
    </div>
</template>
