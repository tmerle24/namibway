<script setup lang="ts">
import { ref, computed } from 'vue';

const props = defineProps<{
    modelValue: string;
    suggestions: string[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const open = ref(false);
const query = ref('');

const filtered = computed(() =>
    props.suggestions.filter(s =>
        s.toLowerCase().includes(query.value.toLowerCase())
    )
);

function openPicker() {
    query.value = '';
    open.value = true;
}

function select(value: string) {
    const trimmed = value.trim();
    if (trimmed) emit('update:modelValue', trimmed);
    open.value = false;
    query.value = '';
}

function handleBlur() {
    // Delay so mousedown on a suggestion registers before blur closes the list
    setTimeout(() => {
        select(query.value || props.modelValue);
    }, 150);
}
</script>

<template>
    <div class="location-picker-wrap">
        <template v-if="open">
            <input
                :ref="(el) => el && (el as HTMLInputElement).focus()"
                v-model="query"
                type="text"
                class="location-input"
                :placeholder="modelValue"
                @blur="handleBlur"
                @keydown.enter.prevent="select(query || modelValue)"
                @keydown.escape="open = false; query = ''"
            />
            <ul v-if="filtered.length" class="location-suggestions">
                <li v-for="s in filtered" :key="s" @mousedown.prevent="select(s)">{{ s }}</li>
            </ul>
        </template>
        <b v-else class="location-editable" @click="openPicker">{{ modelValue || '—' }}</b>
    </div>
</template>
