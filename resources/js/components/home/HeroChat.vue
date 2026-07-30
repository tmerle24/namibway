<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CurrencySwitcher from '@/components/CurrencySwitcher.vue';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { formatPrice } from '@/lib/currency';
import { sendKaiaMessage } from '@/lib/kaia-client';
import type {
    ChatMessage,
    ItineraryPlan,
    SearchIntent,
} from '@/lib/kaia-types';
import { dashboard, login, register } from '@/routes';
import logoLight from '../../../images/logo-light.png';

const emit = defineEmits<{
    (e: 'plan-ready', plan: ItineraryPlan): void;
    (e: 'search-intent', intent: SearchIntent): void;
}>();

const page = usePage();
const { t, tm, locale } = useI18n();

const thinkingStatuses = computed(
    () => tm('chat.thinkingStatuses') as unknown as string[],
);

const messages = ref<ChatMessage[]>([{ role: 'ai', text: t('chat.greeting') }]);

// The greeting is seeded once at mount, but switching locale via the
// LocaleSwitcher doesn't remount this component (Inertia does a partial
// reload) — so it never picks up the new language on its own. Re-translate
// it as long as the user hasn't started chatting yet.
watch(locale, () => {
    if (messages.value.length === 1 && messages.value[0].role === 'ai') {
        messages.value[0].text = t('chat.greeting');
    }
});
const inputText = ref('');
const isTyping = ref(false);
const thinkingIndex = ref(0);
const chatLog = ref<HTMLDivElement | null>(null);
const chatPanel = ref<HTMLDivElement | null>(null);
const chatInput = ref<HTMLInputElement | null>(null);
let thinkingTimer: ReturnType<typeof setInterval> | null = null;

function startThinking() {
    thinkingIndex.value = 0;
    thinkingTimer = setInterval(() => {
        thinkingIndex.value =
            (thinkingIndex.value + 1) % thinkingStatuses.value.length;
    }, 2500);
}

function stopThinking() {
    if (thinkingTimer) {
        clearInterval(thinkingTimer);
        thinkingTimer = null;
    }
}

onUnmounted(stopThinking);

function syncScroll() {
    if (chatLog.value) {
        chatLog.value.scrollTop = chatLog.value.scrollHeight;
    }

    // Also bring the panel's bottom edge into view on the page itself, so the
    // user never has to manually scroll the outer page to see a new message —
    // but only if the panel is already at least partly on screen, so we don't
    // yank scroll position while someone is reading further down the page.
    const panel = chatPanel.value;

    if (panel) {
        const rect = panel.getBoundingClientRect();
        const isPartlyVisible =
            rect.top < window.innerHeight && rect.bottom > 0;
        const hiddenBelowFold = rect.bottom - window.innerHeight;

        if (isPartlyVisible && hiddenBelowFold > 0) {
            window.scrollBy({ top: hiddenBelowFold + 24, behavior: 'smooth' });
        }
    }
}

async function scrollToBottom() {
    await nextTick();
    syncScroll();

    // Web fonts (or an image) can finish loading and reflow the message text
    // a frame or two after the DOM patch lands, leaving the scroll position
    // short of the true bottom — settle it again once layout has caught up.
    requestAnimationFrame(() => requestAnimationFrame(syncScroll));
}

async function sendMessage() {
    const text = inputText.value.trim();

    if (!text || isTyping.value) {
        return;
    }

    messages.value.push({ role: 'user', text });
    inputText.value = '';
    isTyping.value = true;
    startThinking();
    await scrollToBottom();

    try {
        const result = await sendKaiaMessage(messages.value);

        if (result.type === 'question') {
            messages.value.push({ role: 'ai', text: result.text });
        } else if (result.type === 'itinerary') {
            messages.value.push({ role: 'ai', text: t('chat.itineraryReady') });
            emit('plan-ready', result.plan);
        } else if (result.type === 'search_intent') {
            messages.value.push({
                role: 'ai',
                text: t('chat.searchTriggered'),
            });
            emit('search-intent', result.intent);
        } else if (result.type === 'recommendation') {
            messages.value.push({
                role: 'ai',
                text: result.intro || t('chat.recommendationIntro'),
                recommendation: result.listing,
            });
        } else {
            messages.value.push({ role: 'ai', text: result.text });
        }
    } catch {
        messages.value.push({ role: 'ai', text: t('chat.error') });
    }

    stopThinking();
    isTyping.value = false;

    await scrollToBottom();

    // The input is disabled while Kaia is "typing", which drops focus —
    // bring it back so the user can keep typing without reaching for the mouse.
    await nextTick();
    chatInput.value?.focus();
}
</script>

<template>
    <div id="kaia-hero" class="hero">
        <svg
            class="hero-bg"
            viewBox="0 0 1040 340"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <circle cx="520" cy="140" r="60" fill="var(--gold)" opacity="0.9" />
            <path
                d="M0 260 Q140 190 300 250 T620 240 T1040 255 V340 H0 Z"
                fill="#C97A3E"
                opacity="0.55"
            />
            <path
                d="M0 300 Q180 230 400 290 T780 280 T1040 300 V340 H0 Z"
                fill="#B5651D"
                opacity="0.85"
            />
            <path
                d="M0 330 Q220 280 480 320 T1040 325 V340 H0 Z"
                fill="#8C4A15"
            />
            <g opacity="0.8" transform="translate(120,232)">
                <line
                    x1="0"
                    y1="0"
                    x2="0"
                    y2="46"
                    stroke="#241C15"
                    stroke-width="4"
                    stroke-linecap="round"
                />
                <path
                    d="M0 6 C -26 -4, -38 -22, -30 -30 C -22 -22, -8 -14, 0 6 Z"
                    fill="#241C15"
                />
                <path
                    d="M0 10 C 26 0, 40 -16, 32 -26 C 22 -18, 8 -10, 0 10 Z"
                    fill="#241C15"
                />
            </g>
        </svg>
        <div class="hero-content">
            <div class="hero-nav">
                <div class="brand">
                    <img :src="logoLight" alt="NamibWay" class="brand-logo" />
                </div>
                <div style="display: flex; align-items: center; gap: 8px">
                    <CurrencySwitcher />
                    <LocaleSwitcher />
                    <Link v-if="page.props.auth?.user" :href="dashboard()">{{
                        t('nav.dashboard')
                    }}</Link>
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

            <div class="chat-panel" ref="chatPanel">
                <div class="chat-log" ref="chatLog">
                    <div
                        v-for="(msg, i) in messages"
                        :key="i"
                        :class="['msg', msg.role]"
                    >
                        {{ msg.text }}
                        <a
                            v-if="msg.recommendation"
                            :href="`/listings/${msg.recommendation.slug}`"
                            class="chat-rec-card"
                        >
                            <img
                                v-if="msg.recommendation.image"
                                :src="msg.recommendation.image"
                                :alt="msg.recommendation.name"
                                class="chat-rec-img"
                            />
                            <div class="chat-rec-body">
                                <span class="chat-rec-type">{{
                                    t(
                                        `listing.types.${msg.recommendation.type}`,
                                    )
                                }}</span>
                                <strong class="chat-rec-name">{{
                                    msg.recommendation.name
                                }}</strong>
                                <span
                                    v-if="msg.recommendation.region"
                                    class="chat-rec-region"
                                    >{{ msg.recommendation.region }}</span
                                >
                                <div class="chat-rec-meta">
                                    <span v-if="msg.recommendation.rating"
                                        >★
                                        {{
                                            msg.recommendation.rating.toFixed(1)
                                        }}</span
                                    >
                                    <span
                                        v-if="
                                            formatPrice(
                                                msg.recommendation.price_from,
                                            )
                                        "
                                        >{{ t('chat.from') }}
                                        {{
                                            formatPrice(
                                                msg.recommendation.price_from,
                                            )
                                        }}</span
                                    >
                                </div>
                                <span class="chat-rec-cta">{{
                                    t('chat.viewListing')
                                }}</span>
                            </div>
                        </a>
                    </div>
                    <div v-if="isTyping" class="msg typing">
                        {{ thinkingStatuses[thinkingIndex] }}
                    </div>
                </div>
                <div class="chat-input-row">
                    <input
                        ref="chatInput"
                        v-model="inputText"
                        type="text"
                        :placeholder="t('chat.placeholder')"
                        autocomplete="off"
                        :readonly="isTyping"
                        @keydown.enter="sendMessage"
                    />
                    <button
                        :disabled="isTyping || !inputText.trim()"
                        @click="sendMessage"
                    >
                        {{ t('chat.send') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
