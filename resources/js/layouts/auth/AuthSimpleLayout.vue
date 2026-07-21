<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { home } from '@/routes';
import logoDark from '../../../images/logo-dark.png';
import logoLight from '../../../images/logo-light.png';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="mb-1 flex flex-col items-center gap-2 font-medium"
                    >
                        <img :src="logoDark" alt="NamibWay" class="w-auto dark:hidden" />
                        <img :src="logoLight" alt="NamibWay" class="hidden w-auto dark:block" />
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
