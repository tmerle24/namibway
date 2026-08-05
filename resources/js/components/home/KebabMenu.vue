<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';

interface MenuItem {
    key: string;
    label: string;
    danger?: boolean;
}

// Named `label`, not `ariaLabel` — Vue always treats `aria-*` template
// attributes as fallthrough, never auto-camelCasing them onto a matching
// prop, so an `ariaLabel` prop bound via `:aria-label` in the parent
// template would silently miss and land on the root element instead.
const props = defineProps<{
    items: MenuItem[];
    label: string;
}>();

const emit = defineEmits<{
    (e: 'select', key: string): void;
}>();

const open = ref(false);
const rootEl = ref<HTMLElement | null>(null);

function toggle() {
    open.value = !open.value;
}

function select(key: string) {
    open.value = false;
    emit('select', key);
}

function onDocumentClick(event: MouseEvent) {
    if (rootEl.value && !rootEl.value.contains(event.target as Node)) {
        open.value = false;
    }
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        open.value = false;
    }
}

// Only listen while open — avoids a document-wide handler for every menu on
// the page when most of them are closed most of the time.
watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('click', onDocumentClick);
        document.addEventListener('keydown', onKeydown);
    } else {
        document.removeEventListener('click', onDocumentClick);
        document.removeEventListener('keydown', onKeydown);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div ref="rootEl" class="kebab-menu">
        <button
            type="button"
            class="kebab-menu-trigger"
            :aria-label="props.label"
            :aria-expanded="open"
            @click.stop="toggle"
        >
            ⋮
        </button>
        <div v-if="open" class="kebab-menu-panel" role="menu">
            <button
                v-for="item in props.items"
                :key="item.key"
                type="button"
                role="menuitem"
                class="kebab-menu-item"
                :class="{ 'kebab-menu-item--danger': item.danger }"
                @click="select(item.key)"
            >
                {{ item.label }}
            </button>
        </div>
    </div>
</template>
