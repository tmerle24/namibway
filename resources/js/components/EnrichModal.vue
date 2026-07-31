<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { ref, watch } from 'vue';

const props = defineProps<{
    show: boolean;
    listingSlug: string;
}>();

const emit = defineEmits<{
    close: [];
}>();

const useGooglePlaces = ref(false);
const useClaude = ref(false);
const enriching = ref(false);

watch(
    () => props.show,
    (visible) => {
        if (visible) {
            useGooglePlaces.value = false;
            useClaude.value = false;
        }
    },
);

function confirm() {
    enriching.value = true;

    router.post(
        `/listings/${props.listingSlug}/enrich`,
        {
            use_google_places: useGooglePlaces.value,
            use_claude: useClaude.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                enriching.value = false;
                emit('close');
            },
        },
    );
}
</script>

<template>
    <div v-if="props.show" class="enrich-overlay" @click.self="emit('close')">
        <div class="enrich-modal" role="dialog" aria-modal="true">
            <h2>Enrich this listing</h2>
            <p>
                OpenStreetMap and the listing's own website are always used and
                cost nothing. Google Places and Claude are only queried if
                checked below.
            </p>

            <label class="enrich-checkbox">
                <input v-model="useGooglePlaces" type="checkbox" />
                <span
                    >Use Google Places (paid — GPS, address, phone, photo
                    fallback)</span
                >
            </label>

            <label class="enrich-checkbox">
                <input v-model="useClaude" type="checkbox" />
                <span
                    >Use Claude AI (paid — structured data extraction &amp;
                    description text)</span
                >
            </label>

            <div class="enrich-actions">
                <button
                    type="button"
                    class="enrich-cancel"
                    :disabled="enriching"
                    @click="emit('close')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="enrich-confirm"
                    :disabled="enriching"
                    @click="confirm"
                >
                    <Sparkles :size="14" />
                    {{ enriching ? 'Queuing…' : 'Enrich' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.enrich-overlay {
    position: fixed;
    inset: 0;
    z-index: 300;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(17, 24, 39, 0.55);
    padding: 16px;
}

.enrich-modal {
    background: #111827;
    color: #e5e7eb;
    border-radius: 14px;
    padding: 24px;
    max-width: 420px;
    width: 100%;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
}

.enrich-modal h2 {
    font-size: 18px;
    margin: 0 0 8px;
}

.enrich-modal > p {
    font-size: 13px;
    color: #9ca3af;
    margin: 0 0 18px;
}

.enrich-checkbox {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 13px;
    line-height: 1.4;
    margin-bottom: 12px;
    cursor: pointer;
}

.enrich-checkbox input {
    margin-top: 2px;
    flex-shrink: 0;
}

.enrich-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 18px;
}

.enrich-cancel {
    background: none;
    border: 1px solid #374151;
    color: #e5e7eb;
    border-radius: 8px;
    padding: 9px 16px;
    font-weight: 600;
    font-size: 13.5px;
    cursor: pointer;
}

.enrich-confirm {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f59e0b;
    color: #111827;
    border: none;
    border-radius: 8px;
    padding: 9px 16px;
    font-weight: 700;
    font-size: 13.5px;
    cursor: pointer;
}

.enrich-confirm:hover:not(:disabled) {
    background: #d97706;
}

.enrich-cancel:disabled,
.enrich-confirm:disabled {
    opacity: 0.5;
    cursor: default;
}
</style>
