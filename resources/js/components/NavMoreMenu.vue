<script setup lang="ts">
import { Menu } from '@lucide/vue';
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const open = ref(false);
const root = ref<HTMLElement | null>(null);

function toggle() {
    open.value = !open.value;
}

function onClickOutside(event: MouseEvent) {
    if (
        open.value &&
        root.value &&
        !root.value.contains(event.target as Node)
    ) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onClickOutside));
onUnmounted(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <div ref="root" class="nav-more">
        <button
            type="button"
            class="icon-link"
            :aria-label="t('nav.more')"
            :aria-expanded="open"
            @click="toggle"
        >
            <Menu :size="16" />
        </button>
        <div v-if="open" class="nav-more-panel">
            <slot />
        </div>
    </div>
</template>
