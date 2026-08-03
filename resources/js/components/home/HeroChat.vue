<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, LogIn, LogOut, UserPlus } from '@lucide/vue';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CurrencySwitcher from '@/components/CurrencySwitcher.vue';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import NavMoreMenu from '@/components/NavMoreMenu.vue';
import { formatPrice } from '@/lib/currency';
import { sendKaiaMessage } from '@/lib/kaia-client';
import type {
    ChatMessage,
    ItineraryPlan,
    ListingRecommendation,
    SearchIntent,
} from '@/lib/kaia-types';
import { dashboard, home, login, logout, register } from '@/routes';
import logoLight from '../../../images/logo-light.png';

// Same per-category placeholder set used by ExploreSection/ListingDetail, so
// a listing without a photo still shows something on-brand here instead of
// no image at all.
const CATEGORY_IMAGES: Record<ListingRecommendation['type'], string[]> = {
    accommodation: [
        '/images/explore/accommodation-1.jpg',
        '/images/explore/accommodation-2.jpg',
        '/images/explore/accommodation-3.jpg',
        '/images/explore/accommodation-4.jpg',
    ],
    activity: [
        '/images/explore/activity-1.jpg',
        '/images/explore/activity-2.jpg',
        '/images/explore/activity-3.jpg',
        '/images/explore/activity-4.jpg',
    ],
    restaurant: [
        '/images/explore/restaurant-1.jpg',
        '/images/explore/restaurant-2.jpg',
        '/images/explore/restaurant-3.jpg',
        '/images/explore/restaurant-4.jpg',
    ],
    vehicle: [
        '/images/explore/vehicle-1.jpg',
        '/images/explore/vehicle-2.jpg',
        '/images/explore/vehicle-3.jpg',
        '/images/explore/vehicle-4.jpg',
    ],
};

function recommendationImage(rec: ListingRecommendation): string {
    if (rec.image) {
        return rec.image;
    }

    const fallbacks = CATEGORY_IMAGES[rec.type];

    return fallbacks[rec.id % fallbacks.length];
}

const emit = defineEmits<{
    (e: 'plan-ready', plan: ItineraryPlan): void;
    (e: 'search-intent', intent: SearchIntent): void;
}>();

const page = usePage();
const { t, tm, locale } = useI18n();

function handleLogout() {
    router.flushAll();
}

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

// Web Speech API today; once the Capacitor wrapper lands this is the spot to
// swap in a native STT plugin (the WebView implementation is unreliable on
// iOS) — everything else just consumes `inputText`, so the swap is isolated
// here.
const SpeechRecognitionCtor =
    typeof window !== 'undefined'
        ? (window.SpeechRecognition ?? window.webkitSpeechRecognition)
        : undefined;
const isVoiceSupported = !!SpeechRecognitionCtor;
const isListening = ref(false);
let recognition: InstanceType<
    NonNullable<typeof SpeechRecognitionCtor>
> | null = null;

const speechLocales: Record<string, string> = {
    en: 'en-US',
    de: 'de-DE',
    nl: 'nl-NL',
    fr: 'fr-FR',
    es: 'es-ES',
};

function stopListening() {
    isListening.value = false;
    recognition?.stop();
    recognition = null;
}

function toggleVoiceInput() {
    if (!SpeechRecognitionCtor || isTyping.value) {
        return;
    }

    if (isListening.value) {
        stopListening();

        return;
    }

    recognition = new SpeechRecognitionCtor();
    recognition.lang = speechLocales[locale.value] ?? 'en-US';
    recognition.interimResults = true;

    recognition.onresult = (event) => {
        let transcript = '';

        for (let i = event.resultIndex; i < event.results.length; i++) {
            transcript += event.results[i][0].transcript;
        }

        inputText.value = transcript;
    };
    recognition.onend = () => {
        isListening.value = false;
    };
    recognition.onerror = () => {
        isListening.value = false;
    };

    isListening.value = true;
    recognition.start();
}

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

onUnmounted(() => {
    stopThinking();
    stopListening();
});

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

// Silent retries: most failures (timeouts, a dropped connection, a 5xx that
// slips past the backend's own retry) resolve themselves within a couple of
// seconds, so we retry quietly — with the "thinking" indicator still
// showing — before ever telling the user anything went wrong.
const MAX_SILENT_RETRIES = 2;

function wait(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function applyResult(
    result: Awaited<ReturnType<typeof sendKaiaMessage>>,
): boolean {
    if (result.type === 'question') {
        messages.value.push({ role: 'ai', text: result.text });
    } else if (result.type === 'itinerary') {
        messages.value.push({ role: 'ai', text: t('chat.itineraryReady') });
        emit('plan-ready', result.plan);
    } else if (result.type === 'search_intent') {
        messages.value.push({ role: 'ai', text: t('chat.searchTriggered') });
        emit('search-intent', result.intent);
    } else if (result.type === 'recommendation') {
        messages.value.push({
            role: 'ai',
            text: result.intro || t('chat.recommendationIntro'),
            recommendation: result.listing,
        });
    } else if (result.type === 'error') {
        return false;
    }

    return true;
}

async function requestKaiaReply() {
    // History Kaia should actually see — a previous failed-attempt bubble
    // never happened as far as the conversation is concerned.
    const history = messages.value.filter((m) => !m.failed);

    for (let attempt = 0; attempt <= MAX_SILENT_RETRIES; attempt++) {
        try {
            const result = await sendKaiaMessage(history);

            if (applyResult(result)) {
                return;
            }
        } catch {
            // network error — fall through to retry/backoff below
        }

        if (attempt < MAX_SILENT_RETRIES) {
            await wait(1000 * (attempt + 1));
        }
    }

    messages.value.push({ role: 'ai', text: t('chat.error'), failed: true });
}

async function runKaiaRequest() {
    isTyping.value = true;
    startThinking();
    await scrollToBottom();

    await requestKaiaReply();

    stopThinking();
    isTyping.value = false;

    await scrollToBottom();

    // The input is disabled while Kaia is "typing", which drops focus —
    // bring it back so the user can keep typing without reaching for the mouse.
    await nextTick();
    chatInput.value?.focus();
}

async function sendMessage() {
    const text = inputText.value.trim();

    if (!text || isTyping.value) {
        return;
    }

    messages.value.push({ role: 'user', text });
    inputText.value = '';

    await runKaiaRequest();
}

async function retryLastMessage() {
    if (isTyping.value) {
        return;
    }

    const last = messages.value[messages.value.length - 1];

    if (last?.failed) {
        messages.value.pop();
    }

    await runKaiaRequest();
}
</script>

<template>
    <div class="hero-nav">
        <div class="hero-nav-inner">
            <Link :href="home()" class="brand">
                <img :src="logoLight" alt="NamibWay" class="brand-logo" />
            </Link>
            <div class="hero-nav-actions">
                <LocaleSwitcher />
                <NavMoreMenu>
                    <CurrencySwitcher variant="full" />
                    <div class="nav-more-divider"></div>
                    <Link
                        v-if="page.props.auth?.user"
                        :href="dashboard()"
                        class="nav-more-link"
                    >
                        <LayoutDashboard :size="14" />
                        {{ t('nav.dashboard') }}
                    </Link>
                    <template v-else>
                        <Link :href="login()" class="nav-more-link">
                            <LogIn :size="14" />
                            {{ t('nav.login') }}
                        </Link>
                        <Link :href="register()" class="nav-more-link">
                            <UserPlus :size="14" />
                            {{ t('nav.register') }}
                        </Link>
                    </template>
                    <Link
                        v-if="page.props.auth?.user"
                        :href="logout()"
                        as="button"
                        class="nav-more-link nav-more-link--button"
                        @click="handleLogout"
                    >
                        <LogOut :size="14" />
                        {{ t('nav.logout') }}
                    </Link>
                </NavMoreMenu>
            </div>
        </div>
    </div>
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
            <div class="hero-head">
                <h1>{{ t('hero.title') }}</h1>
                <p>{{ t('hero.subtitle') }}</p>
            </div>

            <div class="chat-panel" ref="chatPanel">
                <div class="chat-log" ref="chatLog">
                    <div
                        v-for="(msg, i) in messages"
                        :key="i"
                        :class="['msg', msg.role, { failed: msg.failed }]"
                    >
                        {{ msg.text }}
                        <template v-if="msg.failed">
                            <br />
                            <button
                                type="button"
                                class="chat-retry-btn"
                                @click="retryLastMessage"
                            >
                                {{ t('chat.retry') }}
                            </button>
                        </template>
                        <a
                            v-if="msg.recommendation"
                            :href="`/listings/${msg.recommendation.slug}`"
                            class="chat-rec-card"
                        >
                            <img
                                :src="recommendationImage(msg.recommendation)"
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
                        :placeholder="
                            isListening
                                ? t('chat.listening')
                                : t('chat.placeholder')
                        "
                        autocomplete="off"
                        :readonly="isTyping"
                        @keydown.enter="sendMessage"
                    />
                    <button
                        v-if="isVoiceSupported"
                        type="button"
                        class="chat-mic-btn"
                        :class="{ listening: isListening }"
                        :disabled="isTyping"
                        :aria-label="
                            isListening
                                ? t('chat.stopListening')
                                : t('chat.voiceInput')
                        "
                        :title="
                            isListening
                                ? t('chat.stopListening')
                                : t('chat.voiceInput')
                        "
                        @click="toggleVoiceInput"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            width="18"
                            height="18"
                            aria-hidden="true"
                        >
                            <path
                                fill="currentColor"
                                d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Z"
                            />
                            <path
                                fill="currentColor"
                                d="M19 11a1 1 0 0 0-2 0 5 5 0 0 1-10 0 1 1 0 0 0-2 0 7 7 0 0 0 6 6.92V20H9a1 1 0 0 0 0 2h6a1 1 0 0 0 0-2h-2v-2.08A7 7 0 0 0 19 11Z"
                            />
                        </svg>
                    </button>
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
