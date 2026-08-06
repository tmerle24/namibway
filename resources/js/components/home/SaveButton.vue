<script setup lang="ts">
import { Bookmark, BookmarkCheck } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { savePlan } from '@/lib/kaia-client';
import type { ItineraryPlan } from '@/lib/kaia-types';

const props = defineProps<{
    plan: ItineraryPlan;
    // Same whole-session token as ShareButton's identical prop — if the
    // plan auto-persisted already (see ItinerarySection's runPersist), this
    // button should reflect that as already-saved rather than minting a
    // second, orphaned SavedPlan row.
    existingToken?: string | null;
    isLoggedIn?: boolean;
}>();

const emit = defineEmits<{
    (e: 'saved', token: string, url: string): void;
    (e: 'need-auth'): void;
}>();

const { t } = useI18n();

const saving = ref(false);
const saved = ref(!!props.existingToken);

async function onSaveClick() {
    if (saving.value || saved.value) {
        return;
    }

    if (props.isLoggedIn === false) {
        emit('need-auth');

        return;
    }

    saving.value = true;

    try {
        const result = await savePlan(props.plan);
        saved.value = true;
        emit('saved', result.token, result.url);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <button
        type="button"
        class="save-icon-btn"
        :class="{ 'save-icon-btn--saved': saved }"
        :disabled="saving || saved"
        :aria-label="
            saved ? t('itinerary.savedToAccount') : t('itinerary.saveToAccount')
        "
        :title="
            saved ? t('itinerary.savedToAccount') : t('itinerary.saveToAccount')
        "
        @click="onSaveClick"
    >
        <BookmarkCheck v-if="saved" :size="17" />
        <Bookmark v-else :size="17" />
    </button>
</template>

<style scoped>
.save-icon-btn {
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

.save-icon-btn:hover:not(:disabled) {
    background: var(--sand);
    border-color: var(--rust);
}

.save-icon-btn--saved {
    color: var(--rust);
    border-color: var(--rust);
    background: var(--rust-light);
}

.save-icon-btn:disabled {
    cursor: default;
}
</style>
