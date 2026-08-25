<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

/**
 * Kaia in the small slot — her initial on a filled ground. Two colour cases,
 * the same figure with the roles swapped: brown ground with a tan letter on
 * light surfaces, sand ground with a brown letter on dark ones (BRAND.md).
 * The letter is an outline, so it needs no font.
 */
defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
    /** `dark` is for dark surfaces, not a dark-coloured avatar. */
    surface?: 'light' | 'dark';
};

withDefaults(defineProps<Props>(), { surface: 'light' });
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 100 100"
        role="img"
        aria-label="Kaia"
        :class="className"
        v-bind="$attrs"
    >
        <circle
            cx="50"
            cy="50"
            r="50"
            :fill="surface === 'dark' ? '#D6C9B5' : '#3B2418'"
        />
        <g transform="translate(30.728 73.859) scale(0.066 -0.066)">
            <path
                :fill="surface === 'dark' ? '#3B2418' : '#E2AB6C'"
                d="M366 0 179 249 365 483H516L298 219L303 283L526 0ZM58 0V723H189V0Z"
            />
        </g>
    </svg>
</template>
