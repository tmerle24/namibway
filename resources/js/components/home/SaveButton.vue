<script setup lang="ts">
import { Bookmark, BookmarkCheck } from '@lucide/vue';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { AuthRequiredError, claimPlan, savePlan } from '@/lib/kaia-client';
import type { ItineraryPlan } from '@/lib/kaia-types';

const props = defineProps<{
    plan: ItineraryPlan;
    // Same whole-session token as ShareButton's identical prop — if the
    // plan auto-persisted already (see ItinerarySection's runPersist), this
    // button claims that row for the account rather than minting a second,
    // orphaned SavedPlan.
    existingToken?: string | null;
    // Whether the plan is already in *this* traveler's account. Having a token
    // is not the same thing: the autosave persists anonymously, so a plan can
    // be perfectly retrievable and still belong to nobody. Treating a token as
    // "saved" is what used to leave plans stranded outside the account the
    // traveler thought they'd put them in.
    owned?: boolean;
    isLoggedIn?: boolean;
}>();

const emit = defineEmits<{
    (e: 'saved', token: string, url: string): void;
    (e: 'need-auth'): void;
}>();

const { t } = useI18n();

const saving = ref(false);
const saved = ref(!!props.owned);

// Ownership arrives late in the ordinary chat flow: the plan is generated,
// autosaves, and only then does the page know whether it belongs to anyone.
watch(
    () => props.owned,
    (owned) => {
        if (owned) {
            saved.value = true;
        }
    },
);

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
        // The common case: the autosave already persisted this plan, so saving
        // means attaching that row to the account — not writing it again.
        if (props.existingToken) {
            await claimPlan(props.existingToken);
            saved.value = true;
            emit(
                'saved',
                props.existingToken,
                `${window.location.origin}/trip/${props.existingToken}`,
            );

            return;
        }

        const result = await savePlan(props.plan);
        saved.value = true;
        emit('saved', result.token, result.url);
    } catch (e) {
        // Session expired between page load and click — let the parent put the
        // login modal up rather than silently doing nothing.
        if (e instanceof AuthRequiredError) {
            emit('need-auth');
        } else {
            console.warn('Failed to save plan to account:', e);
        }
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
