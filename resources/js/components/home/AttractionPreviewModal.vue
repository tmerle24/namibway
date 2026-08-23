<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatDuration } from '@/lib/duration';
import { fetchAttractionPreview } from '@/lib/kaia-client';
import type { AttractionPreview } from '@/lib/kaia-client';

const props = defineProps<{
    slug: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const { t } = useI18n();

const attraction = ref<AttractionPreview | null>(null);
const loading = ref(true);
const error = ref(false);

async function load() {
    loading.value = true;
    error.value = false;

    try {
        attraction.value = await fetchAttractionPreview(props.slug);
    } catch {
        error.value = true;
    } finally {
        loading.value = false;
    }
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => {
    load();
    window.addEventListener('keydown', onKeydown);
});
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
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
            </div>

            <template v-else-if="attraction">
                <div
                    v-if="attraction.image"
                    class="preview-modal-hero"
                    :style="{ backgroundImage: `url(${attraction.image})` }"
                ></div>

                <div class="preview-modal-body">
                    <span class="preview-modal-type">{{
                        attraction.attraction_type_label
                    }}</span>
                    <h3>{{ attraction.name }}</h3>
                    <p
                        v-if="attraction.area || attraction.city"
                        class="preview-modal-location"
                    >
                        {{ attraction.area || attraction.city }}
                    </p>
                    <p
                        v-if="formatDuration(attraction.duration_minutes, t)"
                        class="preview-modal-duration"
                    >
                        {{ t('itinerary.meta.duration') }}:
                        {{ formatDuration(attraction.duration_minutes, t) }}
                    </p>

                    <p v-if="attraction.short_description">
                        {{ attraction.short_description }}
                    </p>
                    <p v-if="attraction.description">
                        {{ attraction.description }}
                    </p>

                    <!--
                        The part nobody can look up elsewhere. Only rendered
                        where somebody actually checked: null on these means
                        "not established", and printing "no 4x4 needed" from a
                        blank is how a traveller ends up on the wrong track.
                    -->
                    <ul
                        v-if="
                            attraction.requires_4x4 === true ||
                            attraction.requires_permit === true ||
                            attraction.access_note ||
                            attraction.best_time_note
                        "
                        class="attraction-facts"
                    >
                        <li v-if="attraction.requires_4x4 === true">
                            {{ t('attraction.needs4x4') }}
                        </li>
                        <li v-if="attraction.requires_permit === true">
                            {{ t('attraction.needsPermit') }}
                        </li>
                        <li v-if="attraction.access_note">
                            {{ attraction.access_note }}
                        </li>
                        <li v-if="attraction.best_time_note">
                            {{ attraction.best_time_note }}
                        </li>
                    </ul>

                    <p
                        v-if="attraction.photos_attribution"
                        class="preview-modal-attribution"
                    >
                        {{ attraction.photos_attribution }}
                    </p>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.attraction-facts {
    margin: 0.75rem 0 0;
    padding-left: 1.1rem;
    list-style: disc;
    font-size: 0.9rem;
    line-height: 1.5;
    opacity: 0.85;
}

.preview-modal-attribution {
    margin-top: 0.75rem;
    font-size: 0.78rem;
    opacity: 0.6;
}
</style>
