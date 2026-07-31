<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
import { ref, watch } from 'vue';

const props = defineProps<{
    show: boolean;
    listingName: string;
    pendingPhotoCount?: number;
}>();

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();

const termsAccepted = ref(false);
const rightsAccepted = ref(false);

// Re-arm both checkboxes every time the modal opens — accepting once shouldn't
// silently carry over to a different listing/session later.
watch(
    () => props.show,
    (visible) => {
        if (visible) {
            termsAccepted.value = false;
            rightsAccepted.value = false;
        }
    },
);

function confirm() {
    if (!termsAccepted.value || !rightsAccepted.value) {
        return;
    }

    emit('confirm');
}
</script>

<template>
    <div v-if="props.show" class="consent-overlay" @click.self="emit('cancel')">
        <div class="consent-modal" role="dialog" aria-modal="true">
            <h2>Publish "{{ props.listingName }}"?</h2>
            <p>
                This makes the listing visible to everyone on namibway.com
                immediately<template v-if="props.pendingPhotoCount">
                    , including the {{ props.pendingPhotoCount }} photo(s) found
                    on your website</template
                >.
            </p>

            <label class="consent-checkbox">
                <input v-model="termsAccepted" type="checkbox" />
                <span>
                    I have read and accept NamibWay's
                    <a href="/terms" target="_blank" rel="noopener noreferrer"
                        >Terms &amp; Conditions</a
                    >.
                </span>
            </label>

            <label class="consent-checkbox">
                <input v-model="rightsAccepted" type="checkbox" />
                <span>
                    I confirm I have the right to publish this listing's content
                    — including any text and photos — on namibway.com.
                </span>
            </label>

            <div class="consent-actions">
                <button
                    type="button"
                    class="consent-cancel"
                    @click="emit('cancel')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="consent-confirm"
                    :disabled="!termsAccepted || !rightsAccepted"
                    @click="confirm"
                >
                    <ShieldCheck :size="14" />
                    Publish
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.consent-overlay {
    position: fixed;
    inset: 0;
    z-index: 300;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(26, 26, 26, 0.55);
    padding: 16px;
}

.consent-modal {
    background: var(--paper, #fff);
    color: var(--ink, #1a1a1a);
    border-radius: 14px;
    padding: 24px;
    max-width: 440px;
    width: 100%;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
}

.consent-modal h2 {
    font-family: 'Fraunces', serif;
    font-size: 20px;
    margin: 0 0 8px;
}

.consent-modal > p {
    font-size: 13.5px;
    color: #6b6355;
    margin: 0 0 18px;
}

.consent-checkbox {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 13px;
    line-height: 1.4;
    margin-bottom: 12px;
    cursor: pointer;
}

.consent-checkbox input {
    margin-top: 2px;
    flex-shrink: 0;
}

.consent-checkbox a {
    color: var(--rust, #b45309);
    font-weight: 600;
}

.consent-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 18px;
}

.consent-cancel {
    background: none;
    border: 1px solid var(--sand-dark, #d8cfb8);
    border-radius: 8px;
    padding: 9px 16px;
    font-weight: 600;
    font-size: 13.5px;
    cursor: pointer;
}

.consent-confirm {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--rust, #b45309);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 9px 16px;
    font-weight: 600;
    font-size: 13.5px;
    cursor: pointer;
}

.consent-confirm:hover:not(:disabled) {
    background: var(--rust-dark, #92400e);
}

.consent-confirm:disabled {
    opacity: 0.5;
    cursor: default;
}
</style>
