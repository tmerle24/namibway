<script setup lang="ts">
import { Share2 } from '@lucide/vue';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { savePlan } from '@/lib/kaia-client';
import type { ItineraryPlan } from '@/lib/kaia-types';

const props = defineProps<{
    plan: ItineraryPlan;
    // The whole-session token already sitting behind the ?trip= URL — see
    // SaveShareBar's identical prop for why this takes priority over
    // minting a fresh one via savePlan(). This is the *edit* token; it is
    // only used to know a plan already exists, never to build a link.
    existingToken?: string | null;
    // The plan's read-only token — what actually gets handed out. Sharing the
    // edit token would give every recipient write access to the plan.
    shareToken?: string | null;
}>();

const emit = defineEmits<{
    (e: 'saved', token: string, url: string): void;
}>();

const { t } = useI18n();

const resolving = ref(false);
const shareUrl = ref<string | null>(
    props.shareToken
        ? `${window.location.origin}/trip/${props.shareToken}`
        : null,
);
const modalOpen = ref(false);
const copied = ref(false);

// The plan often hasn't finished auto-persisting (ItinerarySection's
// debounced runPersist) by the time this button mounts, so shareToken
// can still be null on mount and arrive a moment later — pick that up
// instead of leaving shareUrl stuck at null forever.
watch(
    () => props.shareToken,
    (token) => {
        if (token && !shareUrl.value) {
            shareUrl.value = `${window.location.origin}/trip/${token}`;
        }
    },
);

async function resolveUrl(): Promise<string | null> {
    if (shareUrl.value) {
        return shareUrl.value;
    }

    // Sharing only needs a token, not an account — savePlan() persists
    // anonymously (session-scoped) just like ItinerarySection's own
    // auto-save, so there's nothing here that requires being logged in.
    resolving.value = true;

    try {
        const result = await savePlan(props.plan);
        shareUrl.value = result.shareUrl ?? result.url;
        emit('saved', result.token, result.url);

        return shareUrl.value;
    } finally {
        resolving.value = false;
    }
}

async function onShareClick() {
    const url = await resolveUrl();

    if (!url) {
        return;
    }

    // Feature-detected rather than viewport-based: Web Share API support
    // tracks "mobile vs. desktop" closely enough in practice, and reflects
    // what the browser can actually do rather than guessing from width.
    if (navigator.share) {
        try {
            await navigator.share({ title: document.title, url });
        } catch {
            // User cancelled, or the share sheet failed for some platform
            // reason — either way there's nothing useful to recover here.
        }

        return;
    }

    modalOpen.value = true;
}

async function copyLink() {
    if (!shareUrl.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(shareUrl.value);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        linkInput.value?.select();
    }
}

const linkInput = ref<HTMLInputElement | null>(null);

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        modalOpen.value = false;
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <button
        type="button"
        class="share-icon-btn"
        :disabled="resolving"
        :aria-label="t('itinerary.share')"
        :title="t('itinerary.share')"
        @click="onShareClick"
    >
        <Share2 :size="17" />
    </button>

    <div
        v-if="modalOpen"
        class="share-modal-backdrop"
        role="dialog"
        aria-modal="true"
        @click.self="modalOpen = false"
    >
        <div class="share-modal-box">
            <div class="share-modal-header">
                <h3>{{ t('itinerary.shareLink') }}</h3>
                <button
                    class="share-modal-close"
                    :aria-label="t('listingPreview.close')"
                    @click="modalOpen = false"
                >
                    ×
                </button>
            </div>
            <div class="share-modal-row">
                <input
                    ref="linkInput"
                    :value="shareUrl"
                    readonly
                    class="share-modal-input"
                    @click="($event.target as HTMLInputElement).select()"
                />
                <button
                    type="button"
                    class="share-modal-copy"
                    @click="copyLink"
                >
                    {{
                        copied
                            ? t('itinerary.linkCopied')
                            : t('itinerary.copyLink')
                    }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.share-icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: 1px solid var(--sand-dark);
    background: var(--paper);
    color: var(--rust-dark);
    cursor: pointer;
    transition:
        background 0.15s,
        border-color 0.15s;
}

.share-icon-btn:hover:not(:disabled) {
    background: var(--sand);
    border-color: var(--rust);
}

.share-icon-btn:disabled {
    opacity: 0.6;
    cursor: default;
}

.share-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(16, 26, 48, 0.55);
    z-index: 280;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.share-modal-box {
    background: var(--paper, #fbf8f1);
    border-radius: 16px;
    width: 100%;
    max-width: 420px;
    padding: 18px 20px 20px;
    box-shadow: 0 24px 60px rgba(16, 26, 48, 0.3);
}

.share-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}

.share-modal-header h3 {
    font-family: 'Fraunces', serif;
    font-size: 17px;
    font-weight: 500;
    margin: 0;
    color: var(--ink, #241c15);
}

.share-modal-close {
    width: 30px;
    height: 30px;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(16, 26, 48, 0.08);
    color: var(--ink, #241c15);
    border: none;
    border-radius: 999px;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
}

.share-modal-close:hover {
    background: rgba(16, 26, 48, 0.16);
}

.share-modal-row {
    display: flex;
    gap: 8px;
}

.share-modal-input {
    flex: 1;
    min-width: 0;
    font-size: 13px;
    font-family: monospace;
    padding: 8px 10px;
    border: 1px solid var(--sand-dark, #d6c9b5);
    border-radius: 8px;
    background: #fff;
    color: var(--ink, #241c15);
}

.share-modal-copy {
    flex-shrink: 0;
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid var(--sand-dark, #d6c9b5);
    background: var(--night, #1b2a4a);
    color: var(--paper, #fbf8f1);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.share-modal-copy:hover {
    background: var(--night-deep, #101a30);
}
</style>
