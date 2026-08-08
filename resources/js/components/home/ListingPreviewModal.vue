<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ImageLightbox from '@/components/ImageLightbox.vue';
import { formatPrice } from '@/lib/currency';
import { formatDuration } from '@/lib/duration';
import { fetchListingPreview } from '@/lib/kaia-client';
import type { ListingPreview } from '@/lib/kaia-client';
import { show } from '@/routes/listings';

const props = defineProps<{
    slug: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const { t } = useI18n();

const listing = ref<ListingPreview | null>(null);
const loading = ref(true);
const error = ref(false);
const lightboxIndex = ref<number | null>(null);

async function load() {
    loading.value = true;
    error.value = false;

    try {
        listing.value = await fetchListingPreview(props.slug);
    } catch {
        error.value = true;
    } finally {
        loading.value = false;
    }
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape' && lightboxIndex.value === null) {
        emit('close');
    }
}

onMounted(() => {
    load();
    window.addEventListener('keydown', onKeydown);
});
onUnmounted(() => window.removeEventListener('keydown', onKeydown));

const fullPageUrl = show({ listing: props.slug }).url;
</script>

<template>
    <div
        class="preview-modal-backdrop"
        role="dialog"
        aria-modal="true"
        @click.self="emit('close')"
    >
        <div class="preview-modal-box">
            <button
                class="preview-modal-close"
                :aria-label="t('listingPreview.close')"
                @click="emit('close')"
            >
                ×
            </button>

            <div v-if="loading" class="preview-modal-loading">
                {{ t('listingPreview.loading') }}
            </div>

            <div v-else-if="error" class="preview-modal-error">
                <p>{{ t('listingPreview.error') }}</p>
                <a
                    :href="fullPageUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    {{ t('listingPreview.viewFullPage') }}
                </a>
            </div>

            <template v-else-if="listing">
                <div
                    v-if="listing.image"
                    class="preview-modal-hero"
                    :style="{ backgroundImage: `url(${listing.image})` }"
                    @click="lightboxIndex = 0"
                ></div>

                <div class="preview-modal-body">
                    <span class="preview-modal-type">{{
                        t(`listing.types.${listing.type}`)
                    }}</span>
                    <h3>{{ listing.name }}</h3>
                    <p
                        v-if="listing.city || listing.region"
                        class="preview-modal-location"
                    >
                        {{ listing.city || listing.region }}
                    </p>
                    <p
                        v-if="listing.rating !== null"
                        class="preview-modal-rating"
                    >
                        ★ {{ listing.rating.toFixed(1) }}
                        <span v-if="listing.rating_count">
                            ({{
                                t('listing.reviews.count', {
                                    count: listing.rating_count,
                                })
                            }})
                        </span>
                    </p>
                    <p
                        v-if="formatDuration(listing.duration_minutes, t)"
                        class="preview-modal-duration"
                    >
                        {{ t('itinerary.meta.duration') }}:
                        {{ formatDuration(listing.duration_minutes, t) }}
                    </p>
                    <p
                        v-if="formatPrice(listing.price_from)"
                        class="preview-modal-price"
                    >
                        {{ t('listing.from') }}
                        {{ formatPrice(listing.price_from) }}
                    </p>

                    <div
                        v-if="listing.gallery.length"
                        class="preview-modal-gallery"
                    >
                        <button
                            v-for="(src, i) in listing.gallery.slice(0, 6)"
                            :key="i"
                            type="button"
                            class="preview-modal-gallery-thumb"
                            @click="lightboxIndex = listing.image ? i + 1 : i"
                        >
                            <img :src="src" :alt="`${listing.name} ${i + 1}`" />
                        </button>
                    </div>

                    <p
                        v-if="listing.short_description || listing.description"
                        class="preview-modal-description"
                    >
                        {{ listing.short_description || listing.description }}
                    </p>

                    <ul
                        v-if="listing.highlights.length"
                        class="preview-modal-highlights"
                    >
                        <li v-for="h in listing.highlights" :key="h">
                            {{ h }}
                        </li>
                    </ul>

                    <div
                        v-if="listing.phone || listing.website"
                        class="preview-modal-contact"
                    >
                        <a
                            v-if="listing.phone"
                            :href="listing.phone_href ?? `tel:${listing.phone}`"
                            >{{ listing.phone }}</a
                        >
                        <a
                            v-if="listing.website"
                            :href="listing.website"
                            target="_blank"
                            rel="noopener noreferrer"
                            >{{ t('listing.contact.website') }}</a
                        >
                    </div>

                    <a
                        :href="fullPageUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="preview-modal-full-link"
                    >
                        {{ t('listingPreview.viewFullPage') }}
                    </a>
                </div>
            </template>
        </div>

        <ImageLightbox
            v-if="listing && lightboxIndex !== null"
            :images="
                listing.image
                    ? [listing.image, ...listing.gallery]
                    : listing.gallery
            "
            :index="lightboxIndex"
            :alt="listing.name"
            @update:index="lightboxIndex = $event"
            @close="lightboxIndex = null"
        />
    </div>
</template>

<style scoped>
.preview-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(16, 26, 48, 0.55);
    z-index: 250;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.preview-modal-box {
    background: var(--paper, #fbf8f1);
    border-radius: 16px;
    width: 100%;
    max-width: 480px;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
    box-shadow: 0 24px 60px rgba(16, 26, 48, 0.3);
}

.preview-modal-close {
    position: absolute;
    top: 10px;
    right: 12px;
    z-index: 2;
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(16, 26, 48, 0.55);
    color: #fff;
    border: none;
    border-radius: 999px;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
}

.preview-modal-close:hover {
    background: rgba(16, 26, 48, 0.75);
}

.preview-modal-loading,
.preview-modal-error {
    padding: 48px 24px;
    text-align: center;
    color: #5b5346;
    font-size: 14px;
}

.preview-modal-error a {
    display: inline-block;
    margin-top: 10px;
    color: var(--rust, #b5651d);
    font-weight: 600;
}

.preview-modal-hero {
    height: 220px;
    background-size: cover;
    background-position: center;
    border-radius: 16px 16px 0 0;
    cursor: pointer;
}

.preview-modal-body {
    padding: 20px 24px 24px;
}

.preview-modal-type {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--rust, #b5651d);
    margin-bottom: 6px;
}

.preview-modal-body h3 {
    font-family: 'Fraunces', serif;
    font-size: 21px;
    font-weight: 500;
    margin: 0 0 4px;
    color: var(--ink, #241c15);
}

.preview-modal-location {
    margin: 0 0 4px;
    font-size: 13.5px;
    color: #5b5346;
}

.preview-modal-rating {
    margin: 0 0 4px;
    font-size: 13.5px;
    color: var(--ink, #241c15);
}

.preview-modal-duration {
    margin: 0 0 4px;
    font-size: 13.5px;
    color: #5b5346;
}

.preview-modal-price {
    margin: 0 0 14px;
    font-size: 14px;
    font-weight: 600;
    color: var(--ink, #241c15);
}

.preview-modal-gallery {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    margin-bottom: 14px;
}

.preview-modal-gallery-thumb {
    flex-shrink: 0;
    width: 64px;
    height: 64px;
    padding: 0;
    border: none;
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
}

.preview-modal-gallery-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.preview-modal-description {
    font-size: 14px;
    line-height: 1.55;
    color: var(--ink, #241c15);
    white-space: pre-line;
    margin: 0 0 14px;
}

.preview-modal-highlights {
    margin: 0 0 14px;
    padding-left: 1.1em;
    font-size: 13.5px;
    color: var(--ink, #241c15);
}

.preview-modal-highlights li {
    margin-bottom: 3px;
}

.preview-modal-contact {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 16px;
    font-size: 13.5px;
}

.preview-modal-contact a {
    color: var(--rust, #b5651d);
    font-weight: 600;
    text-decoration: none;
}

.preview-modal-contact a:hover {
    text-decoration: underline;
}

.preview-modal-full-link {
    display: block;
    text-align: center;
    padding: 11px;
    background: var(--night, #1b2a4a);
    color: var(--paper, #fbf8f1);
    border-radius: 9px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
}

.preview-modal-full-link:hover {
    background: var(--night-deep, #101a30);
}

@media (max-width: 640px) {
    .preview-modal-backdrop {
        padding: 0;
        align-items: flex-end;
    }

    .preview-modal-box {
        max-width: 100%;
        max-height: 92vh;
        border-radius: 16px 16px 0 0;
    }
}
</style>
