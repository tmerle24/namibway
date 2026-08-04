<script setup lang="ts">
// Decorative dune/sun/acacia-tree illustration, originally built as
// TripMap.vue's map-frame background. Kept as its own component because the
// illustration is worth reusing elsewhere (section dividers, empty states,
// etc.) — no props: size and crop it from the outside via CSS on the
// wrapping element (width/height + overflow: hidden). The viewBox scales
// via preserveAspectRatio="xMidYMax slice" (bottom-anchored, cover-style),
// so a short wide container shows just the dune silhouettes, a taller one
// also reveals the sun.
</script>

<template>
    <svg
        viewBox="0 0 1040 340"
        preserveAspectRatio="xMidYMax slice"
        aria-hidden="true"
    >
        <circle
            cx="520"
            cy="90"
            r="46"
            fill="var(--gold, #e3a857)"
            opacity="0.22"
        />
        <path
            d="M0 210 Q140 160 300 205 T620 195 T1040 208 V340 H0 Z"
            fill="var(--rust, #b5651d)"
            opacity="0.1"
        />
        <path
            d="M0 250 Q180 200 400 240 T780 232 T1040 250 V340 H0 Z"
            fill="var(--rust, #b5651d)"
            opacity="0.14"
        />
        <path
            d="M0 290 Q220 250 480 280 T1040 285 V340 H0 Z"
            fill="var(--rust-dark, #8c4a15)"
            opacity="0.16"
        />
        <g opacity="0.2" transform="translate(130,205) scale(0.9)">
            <line
                x1="0"
                y1="0"
                x2="0"
                y2="46"
                stroke="var(--ink, #241c15)"
                stroke-width="4"
                stroke-linecap="round"
            />
            <path
                d="M0 6 C -26 -4, -38 -22, -30 -30 C -22 -22, -8 -14, 0 6 Z"
                fill="var(--ink, #241c15)"
            />
            <path
                d="M0 10 C 26 0, 40 -16, 32 -26 C 22 -18, 8 -10, 0 10 Z"
                fill="var(--ink, #241c15)"
            />
        </g>
        <g opacity="0.14" transform="translate(900,225) scale(0.6)">
            <line
                x1="0"
                y1="0"
                x2="0"
                y2="46"
                stroke="var(--ink, #241c15)"
                stroke-width="4"
                stroke-linecap="round"
            />
            <path
                d="M0 6 C -26 -4, -38 -22, -30 -30 C -22 -22, -8 -14, 0 6 Z"
                fill="var(--ink, #241c15)"
            />
            <path
                d="M0 10 C 26 0, 40 -16, 32 -26 C 22 -18, 8 -10, 0 10 Z"
                fill="var(--ink, #241c15)"
            />
        </g>
    </svg>
</template>
