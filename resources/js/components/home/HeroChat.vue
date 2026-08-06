<script setup lang="ts">
import { Capacitor } from '@capacitor/core';
import { SpeechRecognition as NativeSpeechRecognition } from '@capgo/capacitor-speech-recognition';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import SiteHeader from '@/components/SiteHeader.vue';
import { formatPrice } from '@/lib/currency';
import { sendKaiaMessage } from '@/lib/kaia-client';
import type {
    ChatMessage,
    ItineraryPlan,
    ListingRecommendation,
    SearchIntent,
} from '@/lib/kaia-types';

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

// Wrapped-app voice input uses the native STT plugin (@capacitor-community/
// speech-recognition, backed by Apple's Speech framework / Android's
// SpeechRecognizer) instead of the Web Speech API — WKWebView doesn't
// implement window.SpeechRecognition at all, so the browser API only ever
// worked on Android. The browser/PWA path below is unchanged.
const isNativePlatform = Capacitor.isNativePlatform();

const SpeechRecognitionCtor =
    typeof window !== 'undefined'
        ? (window.SpeechRecognition ?? window.webkitSpeechRecognition)
        : undefined;
const isVoiceSupported = ref(isNativePlatform || !!SpeechRecognitionCtor);
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

if (isNativePlatform) {
    NativeSpeechRecognition.available().then(({ available }) => {
        isVoiceSupported.value = available;
    });

    NativeSpeechRecognition.addListener('partialResults', ({ matches }) => {
        inputText.value = matches?.[0] ?? '';
    });

    NativeSpeechRecognition.addListener('listeningState', ({ status }) => {
        isListening.value = status === 'started';
    });
}

function stopListening() {
    isListening.value = false;

    if (isNativePlatform) {
        void NativeSpeechRecognition.stop();

        return;
    }

    recognition?.stop();
    recognition = null;
}

async function startNativeListening() {
    const permission = await NativeSpeechRecognition.requestPermissions();

    if (permission.speechRecognition !== 'granted') {
        return;
    }

    isListening.value = true;
    NativeSpeechRecognition.start({
        language: speechLocales[locale.value] ?? 'en-US',
        partialResults: true,
        popup: false,
    }).catch(() => {
        isListening.value = false;
    });
}

function toggleVoiceInput() {
    if (!isVoiceSupported.value || isTyping.value) {
        return;
    }

    if (isListening.value) {
        stopListening();

        return;
    }

    if (isNativePlatform) {
        void startNativeListening();

        return;
    }

    if (!SpeechRecognitionCtor) {
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

    if (isNativePlatform) {
        void NativeSpeechRecognition.removeAllListeners();
    }
});

// On mobile, before a plan exists, the Kaia tab is a fullscreen column
// capped to the visible viewport height via --app-vh (see kaia-home.css),
// with the footer nav as a normal static flex child — nothing in this state
// should ever need the *outer* page to scroll, only #kaia-hero's own
// internal overflow. But focusing this input triggers the OS's native
// "scroll focused input into view" behavior, which acts on the outer
// document using its pre-keyboard (taller) height — before our --app-vh
// resize handler has caught up — and scrolls the whole column up, dragging
// the footer nav off the top edge. It only partially self-corrects once
// something else (e.g. typing) forces a reflow. Since outer-page scroll is
// never legitimate in this exact state, just pin it back to zero once the
// keyboard/viewport settle.
function resetOuterScrollIfFullscreenMobile() {
    const page = chatInput.value?.closest('.kaia-page');

    if (
        !page ||
        page.classList.contains('has-plan') ||
        !window.matchMedia('(hover: none), (pointer: coarse)').matches
    ) {
        return;
    }

    requestAnimationFrame(() => {
        requestAnimationFrame(() => window.scrollTo(0, 0));
    });
}

// TEMPORARY diagnostic overlay for the mobile "screen turns blue/black on
// keyboard open" bug. Three prior fix attempts (the --app-vh floor in
// app.ts, resetOuterScrollIfFullscreenMobile above, and the rAF-deferred
// --app-vh write + compositing-layer promotion in app.ts/kaia-home.css)
// shipped to production without resolving it, so this now casts a much
// wider net instead of guessing at another specific mechanism:
//   - a requestAnimationFrame heartbeat, logged at most every ~300ms, for
//     as long as the diagnostic session runs. If this keeps ticking through
//     the broken period, the main thread is fine and it's a pure paint/
//     compositing stall (nothing to do with our JS); if it also stalls,
//     something is genuinely blocking the main thread.
//   - a PerformanceObserver for `longtask` entries (where supported), which
//     directly reports if/when/how-long JS blocked the main thread.
//   - wall-clock time on every line (not just t+Nms), so a line's timestamp
//     can be checked directly against the phone's own status-bar clock in a
//     screenshot — proof of a multi-second gap doesn't depend on trusting
//     our own elapsed-time math.
//   - a wider spread of events (visibility, touch, orientation, page
//     show/hide) in case the freeze is tied to something other than the
//     visualViewport/scroll events we were already watching.
// The overlay stays up for 10s after focus (not just until blur), so a
// screenshot taken right after the keyboard closes still shows the trail.
// Remove this whole block, its template overlay, and the @focus/@blur
// wiring on the chat input once the real cause is found.
const diagnosticLog = ref<string[]>([]);
const showDiagnostics = ref(false);
let diagnosticStart = 0;
let diagnosticRafId: number | null = null;
let diagnosticHideTimer: ReturnType<typeof setTimeout> | null = null;
let diagnosticLongtaskObserver: PerformanceObserver | null = null;
let diagnosticLastHeartbeat = 0;

function diagnosticWallClock(): string {
    const d = new Date();

    return (
        `${d.getHours().toString().padStart(2, '0')}:` +
        `${d.getMinutes().toString().padStart(2, '0')}:` +
        `${d.getSeconds().toString().padStart(2, '0')}.` +
        `${d.getMilliseconds().toString().padStart(3, '0')}`
    );
}

function logDiagnostic(label: string) {
    const vv = window.visualViewport;
    const elapsed = (performance.now() - diagnosticStart)
        .toFixed(0)
        .padStart(5, ' ');
    const appVh = getComputedStyle(document.documentElement).getPropertyValue(
        '--app-vh',
    );
    const entry =
        `${diagnosticWallClock()} t+${elapsed}ms ${label} | vv.h=${vv?.height.toFixed(0)} vv.scale=${vv?.scale.toFixed(3)} ` +
        `vv.top=${vv?.offsetTop.toFixed(0)} win.h=${window.innerHeight} scrollY=${window.scrollY} appVh=${appVh.trim()} vis=${document.visibilityState}`;

    diagnosticLog.value = [entry, ...diagnosticLog.value].slice(0, 200);
}

function diagnosticHeartbeatTick(now: number) {
    if (now - diagnosticLastHeartbeat >= 300) {
        diagnosticLastHeartbeat = now;
        logDiagnostic('tick');
    }

    diagnosticRafId = requestAnimationFrame(diagnosticHeartbeatTick);
}

function diagnosticOnVvResize() {
    logDiagnostic('vv-resize');
}
function diagnosticOnVvScroll() {
    logDiagnostic('vv-scroll');
}
function diagnosticOnWindowScroll() {
    logDiagnostic('win-scroll');
}
function diagnosticOnVisibility() {
    logDiagnostic(`visibilitychange`);
}
function diagnosticOnPageshow() {
    logDiagnostic('pageshow');
}
function diagnosticOnPagehide() {
    logDiagnostic('pagehide');
}
function diagnosticOnTouchstart() {
    logDiagnostic('touchstart');
}
function diagnosticOnOrientation() {
    logDiagnostic('orientationchange');
}

function startDiagnostics() {
    diagnosticStart = performance.now();
    diagnosticLastHeartbeat = diagnosticStart;
    diagnosticLog.value = [];
    showDiagnostics.value = true;
    logDiagnostic(
        `focus | ua=${navigator.userAgent} standalone=${window.matchMedia('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true}`,
    );

    window.visualViewport?.addEventListener('resize', diagnosticOnVvResize);
    window.visualViewport?.addEventListener('scroll', diagnosticOnVvScroll);
    window.addEventListener('scroll', diagnosticOnWindowScroll);
    document.addEventListener('visibilitychange', diagnosticOnVisibility);
    window.addEventListener('pageshow', diagnosticOnPageshow);
    window.addEventListener('pagehide', diagnosticOnPagehide);
    window.addEventListener('touchstart', diagnosticOnTouchstart, {
        passive: true,
    });
    window.addEventListener('orientationchange', diagnosticOnOrientation);

    if ('PerformanceObserver' in window) {
        try {
            diagnosticLongtaskObserver = new PerformanceObserver((list) => {
                for (const e of list.getEntries()) {
                    logDiagnostic(`longtask dur=${e.duration.toFixed(0)}ms`);
                }
            });
            diagnosticLongtaskObserver.observe({ entryTypes: ['longtask'] });
        } catch {
            // Long Tasks API unsupported on this engine — skip silently.
        }
    }

    diagnosticRafId = requestAnimationFrame(diagnosticHeartbeatTick);

    if (diagnosticHideTimer) {
        clearTimeout(diagnosticHideTimer);
    }
    diagnosticHideTimer = setTimeout(() => {
        showDiagnostics.value = false;
    }, 10000);
}

function stopDiagnostics() {
    logDiagnostic('blur');
    window.visualViewport?.removeEventListener('resize', diagnosticOnVvResize);
    window.visualViewport?.removeEventListener('scroll', diagnosticOnVvScroll);
    window.removeEventListener('scroll', diagnosticOnWindowScroll);
    document.removeEventListener('visibilitychange', diagnosticOnVisibility);
    window.removeEventListener('pageshow', diagnosticOnPageshow);
    window.removeEventListener('pagehide', diagnosticOnPagehide);
    window.removeEventListener('touchstart', diagnosticOnTouchstart);
    window.removeEventListener('orientationchange', diagnosticOnOrientation);

    if (diagnosticRafId !== null) {
        cancelAnimationFrame(diagnosticRafId);
        diagnosticRafId = null;
    }

    diagnosticLongtaskObserver?.disconnect();
    diagnosticLongtaskObserver = null;
    // The overlay itself stays visible (auto-hidden by the timer set in
    // startDiagnostics) so a screenshot taken right as the keyboard closes
    // still shows the full trail, not just whatever was on screen at blur.
}

function handleChatInputFocus() {
    resetOuterScrollIfFullscreenMobile();
    startDiagnostics();
}

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
    <SiteHeader />
    <div id="kaia-hero" class="hero">
        <svg
            class="hero-bg"
            viewBox="0 0 1040 340"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
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
        </svg>
        <!-- A Deadvlei-style dead camel thorn tree — bare, gnarled branches
             with no foliage, the classic silhouette of Namibia's most
             photographed landmark. Its own SVG with a normal
             (aspect-ratio-preserving) viewBox, separate from hero-bg above:
             hero-bg deliberately stretches non-uniformly to fill the hero
             at any height, which is fine for the abstract dune shapes but
             warps a recognizable silhouette like this into a squashed mess
             whenever the hero gets much taller than its 340-unit viewBox
             (e.g. the mobile full-height state). -->
        <svg class="hero-tree" viewBox="0 0 100 135" aria-hidden="true">
            <g
                transform="translate(50,130)"
                fill="none"
                stroke="#1c130c"
                stroke-linecap="round"
            >
                <path d="M0 0 C -2 -22 3 -45 -1 -68" stroke-width="7" />
                <path
                    d="M-1 -68 C -12 -78 -22 -82 -27 -92"
                    stroke-width="4.5"
                />
                <path
                    d="M-27 -92 C -33 -100 -42 -104 -46 -112"
                    stroke-width="2.5"
                />
                <path
                    d="M-27 -92 C -24 -102 -22 -110 -18 -118"
                    stroke-width="2.5"
                />
                <path d="M-1 -68 C 6 -80 8 -90 3 -100" stroke-width="4.5" />
                <path d="M3 -100 C 8 -108 16 -112 20 -120" stroke-width="2.5" />
                <path d="M3 -100 C 0 -110 2 -118 -3 -126" stroke-width="2.5" />
                <path d="M-1 -68 C 10 -74 20 -76 27 -86" stroke-width="4" />
                <path d="M27 -86 C 33 -92 40 -94 46 -100" stroke-width="2.2" />
                <path d="M27 -86 C 30 -95 35 -100 33 -108" stroke-width="2.2" />
            </g>
        </svg>
        <div class="hero-content">
            <div class="hero-head">
                <h1>{{ t('hero.title') }}</h1>
                <p>{{ t('hero.subtitle') }}</p>
            </div>

            <div class="hero-sun" aria-hidden="true"></div>

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
                        @focus="handleChatInputFocus"
                        @blur="stopDiagnostics"
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

    <!-- TEMPORARY diagnostic overlay — see comment on diagnosticLog in the
         script block. Remove together with that code once the mobile
         keyboard-open bug is diagnosed. -->
    <div
        v-if="showDiagnostics"
        style="
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(0, 0, 0, 0.85);
            color: #0f0;
            font-family: monospace;
            font-size: 10px;
            line-height: 1.4;
            padding: 8px;
            overflow-y: auto;
            pointer-events: none;
            white-space: pre-wrap;
            word-break: break-all;
        "
    >
        <div v-for="(entry, i) in diagnosticLog" :key="i">{{ entry }}</div>
    </div>
</template>
