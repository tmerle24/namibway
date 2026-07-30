<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { savePlan } from '@/lib/kaia-client';
import type { ItineraryPlan } from '@/lib/kaia-types';
import SaveLoginModal from './SaveLoginModal.vue';

const props = defineProps<{
    plan: ItineraryPlan;
    token: string | null;
    isLoggedIn?: boolean;
}>();

const emit = defineEmits<{
    (e: 'saved', token: string, url: string): void;
    (e: 'need-auth'): void;
}>();

const { t } = useI18n();

const saving = ref(false);
const shareUrl = ref<string | null>(null);
const copied = ref(false);
const copyFailed = ref(false);

// Whether this instance was mounted already pointing at an existing shared
// plan (e.g. the /trip/{token} page), as opposed to becoming shared during
// this session via save(). Only in that case do we offer "save to account".
const viewingExistingShare = !!props.token;

if (props.token) {
    shareUrl.value = window.location.href;
}

async function save() {
    if (saving.value || shareUrl.value) {
        return;
    }

    if (props.isLoggedIn === false) {
        emit('need-auth');

        return;
    }

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
    if (!shareUrl.value) {
        return;
    }

    copyFailed.value = false;

    try {
        await navigator.clipboard.writeText(shareUrl.value);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        // Clipboard API unavailable/denied (e.g. insecure context) — fall
        // back to a manual text selection so the user can still copy.
        copyFailed.value = true;
        selectShareUrlInput();
    }
}

const shareUrlInput = ref<HTMLInputElement | null>(null);

function selectShareUrlInput() {
    shareUrlInput.value?.select();
}

function openPdf(token: string) {
    window.open(`/trip/${token}/pdf`, '_blank');
}

function printPage() {
    window.print();
}

// --- Save to account (bookmark an already-shared plan) ---

const savingToAccount = ref(false);
const savedToAccount = ref(false);
const showAccountAuthModal = ref(false);

async function saveToAccount() {
    if (savingToAccount.value || savedToAccount.value) {
        return;
    }

    if (props.isLoggedIn === false) {
        showAccountAuthModal.value = true;

        return;
    }

    savingToAccount.value = true;

    try {
        await savePlan(props.plan);
        savedToAccount.value = true;
    } finally {
        savingToAccount.value = false;
    }
}

function onAccountAuthenticated() {
    showAccountAuthModal.value = false;
    saveToAccount();
}
</script>

<template>
    <div class="save-share-bar">
        <template v-if="!shareUrl">
            <button
                type="button"
                class="save-btn"
                :disabled="saving"
                @click="save"
            >
                {{ saving ? t('itinerary.saving') : t('itinerary.saveShare') }}
            </button>
        </template>

        <template v-else>
            <div class="share-url-row">
                <span class="share-label">{{ t('itinerary.shareLink') }}</span>
                <input
                    ref="shareUrlInput"
                    :value="shareUrl"
                    readonly
                    class="share-url-input"
                    @click="($event.target as HTMLInputElement).select()"
                />
                <button type="button" class="copy-btn" @click="copyLink">
                    {{
                        copied
                            ? t('itinerary.linkCopied')
                            : t('itinerary.copyLink')
                    }}
                </button>
            </div>
            <p v-if="copyFailed" class="copy-fallback-hint">
                {{ t('itinerary.copyFailed') }}
            </p>
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
                <button
                    v-if="viewingExistingShare"
                    type="button"
                    class="export-btn"
                    :disabled="savingToAccount || savedToAccount"
                    @click="saveToAccount"
                >
                    {{
                        savedToAccount
                            ? t('itinerary.savedToAccount')
                            : savingToAccount
                              ? t('itinerary.saving')
                              : t('itinerary.saveToAccount')
                    }}
                </button>
            </div>
        </template>

        <SaveLoginModal
            v-if="showAccountAuthModal"
            @close="showAccountAuthModal = false"
            @authenticated="onAccountAuthenticated"
        />
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
    transition:
        background 0.15s,
        color 0.15s;
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
    flex-wrap: wrap;
    gap: 8px;
}

.export-btn {
    flex: 1;
    min-width: 100px;
    padding: 7px 12px;
    border-radius: 7px;
    border: 1px solid var(--sand-dark, #d6c9b5);
    background: var(--paper, #faf8f5);
    font-size: 13px;
    cursor: pointer;
    transition: background 0.12s;
}

.export-btn:hover:not(:disabled) {
    background: var(--sand-dark, #d6c9b5);
}

.export-btn:disabled {
    opacity: 0.6;
    cursor: default;
}

.copy-fallback-hint {
    font-size: 11px;
    color: #7a6a5e;
    margin: -2px 0 0;
}
</style>
