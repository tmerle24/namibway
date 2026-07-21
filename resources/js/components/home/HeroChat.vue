<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { DEMO_PLAN, KAIA_INTERVIEW_STEPS, KAIA_SUMMARY_MESSAGE  } from '@/lib/kaia-demo';
import type {ItineraryPlan} from '@/lib/kaia-demo';
import { dashboard, login, register } from '@/routes';
import logoLight from '../../../images/logo-light.png';

const emit = defineEmits<{
    (e: 'plan-ready', plan: ItineraryPlan): void;
}>();

const page = usePage();
const { t } = useI18n();

interface ChatMessage {
    role: 'ai' | 'user';
    text: string;
}

const messages = ref<ChatMessage[]>([{ role: 'ai', text: KAIA_INTERVIEW_STEPS[0] }]);
const inputText = ref('');
const isTyping = ref(false);
const step = ref(0);
const chatLog = ref<HTMLDivElement | null>(null);

async function scrollToBottom() {
    await nextTick();

    if (chatLog.value) {
        chatLog.value.scrollTop = chatLog.value.scrollHeight;
    }
}

async function sendMessage() {
    const text = inputText.value.trim();

    if (!text || isTyping.value) {
return;
}

    messages.value.push({ role: 'user', text });
    inputText.value = '';
    await scrollToBottom();

    isTyping.value = true;
    await new Promise((resolve) => setTimeout(resolve, 700 + Math.random() * 500));
    isTyping.value = false;

    step.value += 1;

    if (step.value < KAIA_INTERVIEW_STEPS.length) {
        messages.value.push({ role: 'ai', text: KAIA_INTERVIEW_STEPS[step.value] });
    } else {
        messages.value.push({ role: 'ai', text: KAIA_SUMMARY_MESSAGE });
        emit('plan-ready', DEMO_PLAN);
    }

    await scrollToBottom();
}
</script>

<template>
    <div class="hero">
        <svg class="hero-bg" viewBox="0 0 1040 340" preserveAspectRatio="none" aria-hidden="true">
            <circle cx="520" cy="140" r="60" fill="var(--gold)" opacity="0.9" />
            <path d="M0 260 Q140 190 300 250 T620 240 T1040 255 V340 H0 Z" fill="#C97A3E" opacity="0.55" />
            <path d="M0 300 Q180 230 400 290 T780 280 T1040 300 V340 H0 Z" fill="#B5651D" opacity="0.85" />
            <path d="M0 330 Q220 280 480 320 T1040 325 V340 H0 Z" fill="#8C4A15" />
            <g opacity="0.8" transform="translate(120,232)">
                <line x1="0" y1="0" x2="0" y2="46" stroke="#241C15" stroke-width="4" stroke-linecap="round" />
                <path d="M0 6 C -26 -4, -38 -22, -30 -30 C -22 -22, -8 -14, 0 6 Z" fill="#241C15" />
                <path d="M0 10 C 26 0, 40 -16, 32 -26 C 22 -18, 8 -10, 0 10 Z" fill="#241C15" />
            </g>
        </svg>
        <div class="hero-content">
            <div class="hero-nav">
                <div class="brand"><img :src="logoLight" alt="NamibWay" class="brand-logo" /></div>
                <div style="display: flex; align-items: center; gap: 8px">
                    <LocaleSwitcher />
                    <Link v-if="page.props.auth?.user" :href="dashboard()">{{ t('nav.dashboard') }}</Link>
                    <template v-else>
                        <Link :href="login()">{{ t('nav.login') }}</Link>
                        <Link :href="register()">{{ t('nav.register') }}</Link>
                    </template>
                </div>
            </div>
            <div class="hero-head">
                <h1>{{ t('hero.title') }}</h1>
                <p>{{ t('hero.subtitle') }}</p>
            </div>

            <div class="chat-panel">
                <div class="chat-log" ref="chatLog">
                    <div v-for="(msg, i) in messages" :key="i" :class="['msg', msg.role]">{{ msg.text }}</div>
                    <div v-if="isTyping" class="msg typing">{{ t('chat.thinking') }}</div>
                </div>
                <div class="chat-input-row">
                    <input
                        v-model="inputText"
                        type="text"
                        :placeholder="t('chat.placeholder')"
                        autocomplete="off"
                        :disabled="isTyping"
                        @keydown.enter="sendMessage"
                    />
                    <button :disabled="isTyping || !inputText.trim()" @click="sendMessage">{{ t('chat.send') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
