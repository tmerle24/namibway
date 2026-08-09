<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { onImageError, thumbAttrs } from '@/lib/media';

interface Destination {
    name: string;
    slug: string;
    blurb: string | null;
    image: string | null;
    region_name: string;
}

const props = defineProps<{
    destinations: Destination[];
}>();

const emit = defineEmits<{
    select: [region: string];
}>();

const { t } = useI18n();

const scrollEl = ref<HTMLDivElement | null>(null);

function scrollByPage(direction: 1 | -1) {
    const el = scrollEl.value;

    if (!el) {
        return;
    }

    el.scrollBy({ left: el.clientWidth * 0.9 * direction, behavior: 'smooth' });
}
</script>

<template>
    <section id="destinations-section" class="destinations-section">
        <div class="section-head">
            <div class="eyebrow">{{ t('destinations.eyebrow') }}</div>
            <h2>{{ t('destinations.title') }}</h2>
            <p>{{ t('destinations.subtitle') }}</p>
        </div>
        <div class="destinations-scroll-wrap">
            <button
                type="button"
                class="destinations-arrow destinations-arrow--prev"
                :aria-label="t('destinations.prev')"
                @click="scrollByPage(-1)"
            >
                ‹
            </button>
            <div class="destinations-scroll" ref="scrollEl">
                <button
                    v-for="destination in props.destinations"
                    :key="destination.slug"
                    type="button"
                    class="destination-card"
                    @click="emit('select', destination.region_name)"
                >
                    <div class="destination-thumb">
                        <img
                            v-if="destination.image"
                            v-bind="thumbAttrs(destination.image, 220)"
                            :alt="destination.name"
                            class="destination-thumb-img"
                            width="220"
                            height="165"
                            loading="lazy"
                            decoding="async"
                            @error="onImageError($event)"
                        />
                    </div>
                    <div class="destination-body">
                        <h3>{{ destination.name }}</h3>
                        <p v-if="destination.blurb">{{ destination.blurb }}</p>
                    </div>
                </button>
            </div>
            <button
                type="button"
                class="destinations-arrow destinations-arrow--next"
                :aria-label="t('destinations.next')"
                @click="scrollByPage(1)"
            >
                ›
            </button>
        </div>
    </section>
</template>
