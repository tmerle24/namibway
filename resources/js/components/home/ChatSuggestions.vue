<script setup lang="ts">
/**
 * The tappable answers under Kaia's last message — the whole point being that
 * a traveler who would rather not type never has to. Renders nothing when
 * there is nothing to offer, so the caller needs no v-if of its own.
 */
defineProps<{
    suggestions: string[];
    disabled?: boolean;
    /** Shown above the chips; omitted for the greeting, where they speak for themselves. */
    hint?: string | null;
}>();

const emit = defineEmits<{ (e: 'pick', suggestion: string): void }>();
</script>

<template>
    <div v-if="suggestions.length" class="chat-suggestions">
        <span v-if="hint" class="chat-suggestions-hint">{{ hint }}</span>
        <div class="chat-suggestion-chips">
            <button
                v-for="suggestion in suggestions"
                :key="suggestion"
                type="button"
                class="chat-suggestion"
                :disabled="disabled"
                @click="emit('pick', suggestion)"
            >
                {{ suggestion }}
            </button>
        </div>
    </div>
</template>
