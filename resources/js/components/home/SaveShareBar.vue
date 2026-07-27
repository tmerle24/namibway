<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ItineraryPlan } from '@/lib/kaia-types';
import { savePlan } from '@/lib/kaia-client';

const props = defineProps<{
    plan: ItineraryPlan;
    token: string | null;
}>();

const emit = defineEmits<{
    (e: 'saved', token: string, url: string): void;
}>();

const { t } = useI18n();

const saving = ref(false);
const shareUrl = ref<string | null>(null);
const copied = ref(false);

// If a token was passed in (shared plan page), derive the URL immediately
if (props.token) {
    shareUrl.value = window.location.href;
}

async function save() {
    if (saving.value || shareUrl.value) return;
    saving.value = true;
    try {
        const result = await savePlan(props.plan);
        shareUrl.value = result.url;
        emit('saved', result.token, result.url);
    } finally {
        saving.value = false;
    }
}

async function copyLink() {
    if (!shareUrl.value) return;
    await navigator.clipboard.writeText(shareUrl.value);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
}

function openPdf(token: string) {
    window.open(`/trip/${token}/pdf`, '_blank');
}

function printPage() {
    window.print();
}
</script>

<template>
    <div class="save-share-bar">
        <template v-if="!shareUrl">
            <button type="button" class="save-btn" :disabled="saving" @click="save">
                {{ saving ? t('itinerary.saving') : t('itinerary.saveCta') }}
            </button>
        </template>

        <template v-else>
            <div class="share-url-row">
                <span class="share-label">{{ t('itinerary.savedLink') }}</span>
                <input
                    :value="shareUrl"
                    readonly
                    class="share-url-input"
                    @click="($event.target as HTMLInputElement).select()"
                />
                <button type="button" class="copy-btn" @click="copyLink">
                    {{ copied ? t('itinerary.copied') : t('itinerary.copyLink') }}
                </button>
            </div>
            <div class="export-row">
                <button
                    v-if="token"
                    type="button"
                    class="export-btn"
                    @click="openPdf(token)"
                >
                    {{ t('itinerary.downloadPdf') }}
                </button>
                <button type="button" class="export-btn" @click="printPage">
                    {{ t('itinerary.print') }}
                </button>
            </div>
        </template>
    </div>
</template>

<style scoped>
.save-share-bar {
    margin-top: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.save-btn {
    width: 100%;
    padding: 10px 16px;
    border-radius: 8px;
    border: 1.5px solid var(--rust, #c0533a);
    background: transparent;
    color: var(--rust, #c0533a);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
}

.save-btn:hover:not(:disabled) {
    background: var(--rust, #c0533a);
    color: #fff;
}

.save-btn:disabled {
    opacity: 0.5;
    cursor: default;
}

.share-url-row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.share-label {
    font-size: 11px;
    color: #7a6a5e;
    white-space: nowrap;
}

.share-url-input {
    flex: 1;
    min-width: 120px;
    font-size: 12px;
    font-family: monospace;
    padding: 5px 8px;
    border: 1px solid var(--sand-dark, #d6c9b5);
    border-radius: 6px;
    background: var(--paper, #faf8f5);
    color: #2c2521;
}

.copy-btn {
    padding: 5px 12px;
    border-radius: 6px;
    border: 1px solid var(--sand-dark, #d6c9b5);
    background: var(--paper, #faf8f5);
    font-size: 12px;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.12s;
}

.copy-btn:hover {
    background: var(--sand-dark, #d6c9b5);
}

.export-row {
    display: flex;
    gap: 8px;
}

.export-btn {
    flex: 1;
    padding: 7px 12px;
    border-radius: 7px;
    border: 1px solid var(--sand-dark, #d6c9b5);
    background: var(--paper, #faf8f5);
    font-size: 13px;
    cursor: pointer;
    transition: background 0.12s;
}

.export-btn:hover {
    background: var(--sand-dark, #d6c9b5);
}
</style>
