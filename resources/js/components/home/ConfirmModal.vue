<script setup lang="ts">
import { useI18n } from 'vue-i18n';

defineProps<{
    message: string;
}>();

const emit = defineEmits<{
    (e: 'confirm'): void;
    (e: 'cancel'): void;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('cancel')">
        <div class="modal-box" role="alertdialog" aria-modal="true">
            <h3>{{ t('itinerary.confirmRemove.title') }}</h3>
            <p class="modal-message">{{ message }}</p>
            <div class="modal-actions">
                <button
                    type="button"
                    class="modal-cancel"
                    @click="emit('cancel')"
                >
                    {{ t('itinerary.confirmRemove.cancel') }}
                </button>
                <button
                    type="button"
                    class="modal-confirm"
                    @click="emit('confirm')"
                >
                    {{ t('itinerary.confirmRemove.confirm') }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(16, 26, 48, 0.55);
    z-index: 300;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-box {
    background: var(--paper, #fbf8f1);
    border-radius: 16px;
    padding: 26px;
    max-width: 360px;
    width: 100%;
    box-shadow: 0 24px 60px rgba(16, 26, 48, 0.3);
}
h3 {
    font-family: 'Fraunces', serif;
    font-size: 18px;
    font-weight: 500;
    margin: 0 0 8px;
    color: var(--ink, #241c15);
}
.modal-message {
    font-size: 13.5px;
    color: #5b5346;
    margin: 0 0 20px;
    line-height: 1.5;
}
.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
.modal-cancel {
    background: none;
    border: 1px solid var(--sand-dark, #ded2b8);
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 600;
    color: #5b5346;
    cursor: pointer;
}
.modal-cancel:hover {
    border-color: #8a7f68;
    color: var(--ink, #241c15);
}
.modal-confirm {
    background: var(--danger, #a13d2f);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}
.modal-confirm:hover {
    background: #872f23;
}
</style>
