<script setup lang="ts">
import social from '@/routes/auth/social';

withDefaults(
    defineProps<{
        label?: string;
    }>(),
    {
        label: 'Or continue with',
    },
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="relative flex items-center">
            <span class="w-full border-t" />
            <span
                class="absolute left-1/2 -translate-x-1/2 bg-background px-2 text-xs text-muted-foreground"
            >
                {{ label }}
            </span>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <a
                :href="social.redirect.url('google')"
                class="inline-flex items-center justify-center gap-2 rounded-md border border-input bg-background px-3 py-2 text-sm font-medium shadow-xs transition-colors hover:bg-accent hover:text-accent-foreground"
            >
                <svg class="size-4" viewBox="0 0 24 24" aria-hidden="true">
                    <path
                        fill="#4285F4"
                        d="M23.52 12.27c0-.85-.08-1.67-.22-2.45H12v4.64h6.47a5.53 5.53 0 0 1-2.4 3.63v3h3.87c2.27-2.09 3.58-5.17 3.58-8.82Z"
                    />
                    <path
                        fill="#34A853"
                        d="M12 24c3.24 0 5.96-1.07 7.94-2.91l-3.87-3c-1.08.72-2.45 1.15-4.07 1.15-3.13 0-5.78-2.11-6.73-4.95H1.27v3.11A12 12 0 0 0 12 24Z"
                    />
                    <path
                        fill="#FBBC05"
                        d="M5.27 14.29a7.2 7.2 0 0 1 0-4.58V6.6H1.27a12 12 0 0 0 0 10.8l4-3.11Z"
                    />
                    <path
                        fill="#EA4335"
                        d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0A12 12 0 0 0 1.27 6.6l4 3.11C6.22 6.86 8.87 4.75 12 4.75Z"
                    />
                </svg>
                Google
            </a>
            <a
                :href="social.redirect.url('facebook')"
                class="inline-flex items-center justify-center gap-2 rounded-md border border-input bg-background px-3 py-2 text-sm font-medium shadow-xs transition-colors hover:bg-accent hover:text-accent-foreground"
            >
                <svg class="size-4" viewBox="0 0 24 24" aria-hidden="true">
                    <path
                        fill="#1877F2"
                        d="M24 12.07C24 5.72 18.63.5 12 .5S0 5.72 0 12.07C0 17.8 4.39 22.55 10.13 23.5v-8.1H7.08v-3.33h3.05V9.41c0-3.01 1.79-4.68 4.53-4.68 1.31 0 2.68.24 2.68.24v2.94h-1.51c-1.49 0-1.96.93-1.96 1.88v2.28h3.33l-.53 3.33h-2.8v8.1C19.61 22.55 24 17.8 24 12.07Z"
                    />
                </svg>
                Facebook
            </a>
        </div>
    </div>
</template>
