<script setup lang="ts">
import { ChevronLeft, ChevronRight, X } from '@lucide/vue';
import { onMounted, onUnmounted } from 'vue';
import { thumb } from '@/lib/media';

const props = defineProps<{
    images: string[];
    index: number;
    alt?: string;
    // Google's Places API terms require crediting photo contributors wherever the
    // photo itself is shown — the gallery grid already credits it below the
    // thumbnails, but the lightbox covers that up as a fullscreen overlay, so it
    // needs its own copy of the same attribution while a photo is actually open.
    attribution?: string | null;
}>();

const emit = defineEmits<{
    close: [];
    'update:index': [number];
}>();

function next() {
    emit('update:index', (props.index + 1) % props.images.length);
}

function prev() {
    emit(
        'update:index',
        (props.index - 1 + props.images.length) % props.images.length,
    );
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        emit('close');
    } else if (event.key === 'ArrowRight') {
        next();
    } else if (event.key === 'ArrowLeft') {
        prev();
    }
}

// Only attached while the lightbox is actually mounted (parent renders this
// component behind a v-if), so there's no need to also gate on a visibility prop.
onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div
        class="lightbox-overlay"
        role="dialog"
        aria-modal="true"
        @click.self="emit('close')"
    >
        <button
            type="button"
            class="lightbox-close"
            aria-label="Close"
            @click="emit('close')"
        >
            <X :size="22" />
        </button>

        <button
            v-if="images.length > 1"
            type="button"
            class="lightbox-nav lightbox-nav--prev"
            aria-label="Previous image"
            @click="prev"
        >
            <ChevronLeft :size="28" />
        </button>

        <!-- Fullscreen, so this asks for the top of the width ladder rather than
             a thumbnail — the win here is format=auto (AVIF/WebP), not pixels. -->
        <img
            :src="thumb(images[index], 1600)"
            :alt="alt ? `${alt} ${index + 1}` : `Image ${index + 1}`"
            class="lightbox-image"
            decoding="async"
        />

        <button
            v-if="images.length > 1"
            type="button"
            class="lightbox-nav lightbox-nav--next"
            aria-label="Next image"
            @click="next"
        >
            <ChevronRight :size="28" />
        </button>

        <div class="lightbox-footer">
            <p v-if="images.length > 1" class="lightbox-counter">
                {{ index + 1 }} / {{ images.length }}
            </p>
            <!-- Straight from Google's own API response (html_attributions), not user
                 input, so v-html is safe here — see ListingDetail.vue's own copy of
                 this same attribution below the gallery grid. -->
            <p
                v-if="attribution"
                class="lightbox-attribution"
                v-html="'Photo: ' + attribution"
            ></p>
        </div>
    </div>
</template>

<style scoped>
.lightbox-overlay {
    position: fixed;
    inset: 0;
    z-index: 400;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.9);
    padding: 24px;
}

.lightbox-image {
    max-width: min(90vw, 1100px);
    max-height: 85vh;
    object-fit: contain;
    border-radius: 8px;
}

.lightbox-close {
    position: absolute;
    top: 20px;
    right: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    background: rgba(255, 255, 255, 0.1);
    border: none;
    border-radius: 999px;
    color: #fff;
    cursor: pointer;
}

.lightbox-close:hover {
    background: rgba(255, 255, 255, 0.2);
}

.lightbox-nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    background: rgba(255, 255, 255, 0.1);
    border: none;
    border-radius: 999px;
    color: #fff;
    cursor: pointer;
}

.lightbox-nav:hover {
    background: rgba(255, 255, 255, 0.2);
}

.lightbox-nav--prev {
    left: 20px;
}

.lightbox-nav--next {
    right: 20px;
}

.lightbox-footer {
    position: absolute;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    max-width: 90vw;
    text-align: center;
}

.lightbox-counter {
    color: rgba(255, 255, 255, 0.8);
    font-size: 13px;
}

.lightbox-attribution {
    color: rgba(255, 255, 255, 0.55);
    font-size: 12px;
}

.lightbox-attribution :deep(a) {
    color: rgba(255, 255, 255, 0.75);
}

@media (max-width: 640px) {
    .lightbox-nav {
        width: 40px;
        height: 40px;
    }

    .lightbox-nav--prev {
        left: 8px;
    }

    .lightbox-nav--next {
        right: 8px;
    }
}
</style>
