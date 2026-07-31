<script setup lang="ts">
import { useI18n } from 'vue-i18n';

const { t, tm } = useI18n();

function scrollToId(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<template>
    <div class="how-it-works">
        <div class="how-path how-path--ai">
            <span class="how-path-badge how-path-badge--ai">{{
                t('howItWorks.ai.badge')
            }}</span>
            <h3 class="how-path-title">{{ t('howItWorks.ai.title') }}</h3>
            <ol class="how-path-steps">
                <li
                    v-for="(step, i) in tm(
                        'howItWorks.ai.steps',
                    ) as unknown as string[]"
                    :key="i"
                >
                    {{ step }}
                </li>
            </ol>
            <button class="how-path-cta" @click="scrollToId('kaia-hero')">
                {{ t('howItWorks.ai.cta') }}
            </button>
        </div>

        <div class="how-or">{{ t('howItWorks.or') }}</div>

        <div class="how-path how-path--search">
            <span class="how-path-badge how-path-badge--search">{{
                t('howItWorks.search.badge')
            }}</span>
            <h3 class="how-path-title">{{ t('howItWorks.search.title') }}</h3>
            <ol class="how-path-steps">
                <li
                    v-for="(step, i) in tm(
                        'howItWorks.search.steps',
                    ) as unknown as string[]"
                    :key="i"
                >
                    {{ step }}
                </li>
            </ol>
            <button
                class="how-path-cta how-path-cta--secondary"
                @click="scrollToId('explore-section')"
            >
                {{ t('howItWorks.search.cta') }}
            </button>
        </div>
    </div>
</template>
