<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import logoDark from '../../images/logo-dark.png';
import logoLight from '../../images/logo-light.png';

interface Props {
    partner: {
        name: string;
        claimed_at: string | null;
        rejected_at: string | null;
    };
    listing: {
        name: string;
        type: string;
    } | null;
    decline_url: string;
    claim_url: string;
}

const props = defineProps<Props>();

const form = useForm({});

function decline() {
    form.post(props.decline_url);
}
</script>

<template>
    <Head title="Not interested — NamibWay" />

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

            <template v-if="partner.claimed_at">
                <h1
                    class="mb-2 text-center text-2xl font-semibold text-stone-900 dark:text-white"
                >
                    Already claimed
                </h1>
                <p class="text-center text-stone-500 dark:text-stone-400">
                    <strong>{{ partner.name }}</strong> was already claimed on
                    {{ partner.claimed_at }}, so it can't be removed this way.
                    Contact us at
                    <a
                        href="mailto:hello@namibway.com"
                        class="text-amber-600 hover:underline"
                        >hello@namibway.com</a
                    >
                    if you'd still like it taken down.
                </p>
            </template>

            <template v-else-if="partner.rejected_at">
                <h1
                    class="mb-2 text-center text-2xl font-semibold text-stone-900 dark:text-white"
                >
                    Done — you're removed
                </h1>
                <p class="text-center text-stone-500 dark:text-stone-400">
                    <strong>{{ partner.name }}</strong> has been unpublished on
                    NamibWay. If you change your mind, use the
                    <a :href="claim_url" class="text-amber-600 hover:underline"
                        >claim link</a
                    >
                    from our email any time.
                </p>
            </template>

            <template v-else>
                <h1
                    class="mb-1 text-center text-2xl font-semibold text-stone-900 dark:text-white"
                >
                    Not interested?
                </h1>
                <p
                    class="mb-8 text-center text-sm text-stone-500 dark:text-stone-400"
                >
                    We'll unpublish
                    <strong>{{ listing?.name ?? partner.name }}</strong>
                    from NamibWay right away. No hard feelings — you can always
                    claim it later using the link in our email.
                </p>

                <button
                    :disabled="form.processing"
                    class="w-full rounded-xl bg-stone-800 px-4 py-3 font-semibold text-white transition hover:bg-stone-900 disabled:opacity-60 dark:bg-stone-700 dark:hover:bg-stone-600"
                    @click="decline"
                >
                    <span v-if="form.processing">Removing…</span>
                    <span v-else>Yes, remove my listing</span>
                </button>

                <a
                    :href="claim_url"
                    class="mt-3 block w-full rounded-xl border border-stone-300 px-4 py-3 text-center font-semibold text-stone-700 transition hover:bg-stone-50 dark:border-stone-600 dark:text-stone-300 dark:hover:bg-stone-700"
                >
                    Actually, let me claim it
                </a>
            </template>
        </div>
    </div>
</template>
