<script setup lang="ts">
import { Capacitor } from '@capacitor/core';
import { SpeechRecognition as NativeSpeechRecognition } from '@capgo/capacitor-speech-recognition';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import DeadTree from '@/components/DeadTree.vue';
import ChatSuggestions from '@/components/home/ChatSuggestions.vue';
import KaiaAvatar from '@/components/KaiaAvatar.vue';
import KaiaWordmark from '@/components/KaiaWordmark.vue';
import SiteHeader from '@/components/SiteHeader.vue';
import { formatPrice } from '@/lib/currency';
import { KaiaRequestError, sendKaiaMessage } from '@/lib/kaia-client';
import { starterSuggestions, suggestionsFor } from '@/lib/kaia-suggestions';
import type {
    ChatMessage,
    ItineraryPlan,
    ListingRecommendation,
    SearchIntent,
} from '@/lib/kaia-types';
import { onImageError, thumbAttrs } from '@/lib/media';

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

/**
 * Today's hero photograph (config/hero.php). null is the drawn hero — both
 * the state of a site with no photos and one of the rotation's own slots.
 * `focus` is a CSS object-position: a hero crops hard and differently per
 * viewport, so which part of the frame has to survive belongs to the photo,
 * not the layout. `scrim` picks how hard the overlay works — see the CSS.
 */
export type HeroPhoto = {
    slug: string;
    url: string;
    credit: string | null;
    focus: string;
    scrim: 'strong' | 'light';
};

const props = defineProps<{
    photo?: HeroPhoto | null;
    // Whether a plan already exists on the page. Changes what leaving the
    // chat means on a phone: back to the hero when there is nothing else to
    // show, back to the finished plan when there is.
    hasPlan?: boolean;
    // The parent's copy of `chat-active` handed back. The parent turns
    // full-screen chat off for reasons this component cannot see — a plan
    // arriving, a tab being tapped, the traveler leaving the Kaia tab — and
    // without hearing about it the guard in activateChat() below would stay
    // latched on, so tapping back into the panel would never re-enter the
    // mode it thinks is already active.
    chatActive?: boolean;
}>();

const emit = defineEmits<{
    (e: 'plan-ready', plan: ItineraryPlan): void;
    (e: 'search-intent', intent: SearchIntent): void;
    (e: 'chat-active', active: boolean): void;
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

// Seconds left on a spent rate limit, or null when nothing is being waited
// out. A refusal with a clock on it is not a dead end: the bubble counts down
// and the turn resumes by itself, so the traveler is not left tapping Retry
// into the same wall — which is all the old bubble could offer.
const resumeIn = ref<number | null>(null);

// Sending is off while Kaia is answering and while the window is closed. The
// second half matters: a message sent during the wait would be refused, spend
// an attempt and push the window out again.
const chatBusy = computed(() => isTyping.value || resumeIn.value !== null);
const thinkingIndex = ref(0);
const chatLog = ref<HTMLDivElement | null>(null);
const chatPanel = ref<HTMLDivElement | null>(null);
const chatInput = ref<HTMLInputElement | null>(null);
let thinkingTimer: ReturnType<typeof setInterval> | null = null;

// What the traveler can answer by tapping right now. Two sources, and only
// ever one of them at a time: the curated openers while the greeting is still
// the whole conversation, and afterwards whatever slot Kaia's own last message
// declared. Anchored to the *last* message on purpose — an answered question's
// buttons go away by themselves, and a plan being ready (a message with no
// slot) clears them without anyone having to remember to.
const lastMessage = computed(
    () => messages.value[messages.value.length - 1] ?? null,
);

const isGreetingOnly = computed(() => messages.value.length === 1);

const suggestions = computed<string[]>(() => {
    if (isTyping.value) {
        return [];
    }

    if (isGreetingOnly.value) {
        return starterSuggestions(tm);
    }

    const last = lastMessage.value;

    if (!last || last.role !== 'ai' || last.failed) {
        return [];
    }

    return suggestionsFor(last.awaiting, locale.value, tm);
});

const suggestionsHint = computed(() =>
    isGreetingOnly.value ? t('chat.startersHint') : t('chat.suggestionsHint'),
);

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
    if (!isVoiceSupported.value || chatBusy.value) {
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

// #kaia-hero's `100dvh`-based min-height in the mobile full-screen chat
// state (see the `chat-fullscreen` rules in kaia-home.css) accounts for
// Safari's collapsing toolbar but not the on-screen keyboard — WebKit
// treats the keyboard as an overlay on top of the page, not a change to
// the viewport, so `dvh` stays sized for the pre-keyboard screen. Left
// alone, the chat column (and the input row riding at its bottom) never
// shrinks to make room, so the input ends up positioned behind the
// keyboard with nothing visible to bring it back into view.
// window.visualViewport is the one API that does track the keyboard —
// mirror the gap between it and the layout viewport into a CSS var so the
// fullscreen min-height calc can subtract it instead.
function updateKeyboardInset() {
    const vv = window.visualViewport;

    if (!vv) {
        return;
    }

    const inset = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
    document.documentElement.style.setProperty(
        '--keyboard-inset',
        `${inset}px`,
    );
    // The bottom tab bar is pinned to the layout viewport, which the keyboard
    // now covers — so while it is up the bar is invisible whatever we do, and
    // the room it reserves belongs to the conversation instead. The class
    // (rather than reading the var in CSS) is what lets the stylesheet both
    // hide the bar and zero --mobile-nav-space in one place.
    //
    // A threshold, not `> 0`: the gap is a couple of pixels off zero on some
    // Android browsers with no keyboard at all, and collapsing the tab bar
    // for that would make it flicker on every scroll.
    document.documentElement.classList.toggle('keyboard-open', inset > 80);
}

onMounted(() => {
    updateKeyboardInset();
    window.visualViewport?.addEventListener('resize', updateKeyboardInset);
    window.visualViewport?.addEventListener('scroll', updateKeyboardInset);
});

onUnmounted(() => {
    stopThinking();
    stopCountdown();
    stopListening();
    unlockBodyScroll();
    window.visualViewport?.removeEventListener('resize', updateKeyboardInset);
    window.visualViewport?.removeEventListener('scroll', updateKeyboardInset);
    document.documentElement.style.removeProperty('--keyboard-inset');
    document.documentElement.classList.remove('keyboard-open');

    if (isNativePlatform) {
        void NativeSpeechRecognition.removeAllListeners();
    }
});

// Same fix already proven out in Wisherful (git/wishlist's ListToolbar.vue)
// for its own fixed-overlay text input: on iOS, focusing an input triggers
// the OS's native "scroll it above the keyboard" behavior, which — however
// the surrounding layout is built — ends up dragging the whole page (and
// even position:fixed elements) around while the keyboard opens. Taking the
// body out of normal flow entirely while the input is focused removes the
// one thing that behavior needs to act on: there's no scrollable document
// left to scroll. Restored (and the real scroll position reapplied) on blur.
let savedBodyScrollY = 0;

// Tells Welcome.vue to switch the mobile Kaia tab into full-screen chat
// mode (hero title and illustrations hidden, chat panel stretched to fill
// the screen between the header and the tab bar) — see the `chat-fullscreen`
// rules in kaia-home.css. Sticky on purpose: once the conversation has
// started, tapping the log or briefly blurring the input to hit "send"
// shouldn't collapse it back to the hero view. Welcome.vue turns it off
// again — leaving the Kaia tab, a plan arriving, the Kaia tab tapped while
// the chat is up — and the watch below hears about that through
// `chatActive`, which is what keeps this flag from latching.
//
// Guarded by `activated` so repeat calls (every subsequent focus) don't
// keep re-running the scroll reset below — only the first activation needs
// it, and running it again while the user is mid-conversation would yank
// their scroll position for no reason.
let chatActivated = false;

watch(
    () => props.chatActive,
    (active) => {
        if (!active) {
            chatActivated = false;
        }
    },
);

async function activateChat() {
    if (chatActivated) {
        return;
    }

    chatActivated = true;
    emit('chat-active', true);

    if (!window.matchMedia('(hover: none), (pointer: coarse)').matches) {
        return;
    }

    // Wait for Welcome.vue's `chat-fullscreen` class — and the CSS reflow it
    // drives (hero content hidden, chat panel stretched to fill the screen,
    // input pinned at the very bottom of it) — to actually land before
    // touching scroll. Resetting scroll against the *old* layout would just
    // leave whatever offset the page happened to be at, which then throws
    // off the new one: the full-screen column is sized to exactly fill the
    // viewport on the assumption that it starts flush at scrollY 0.
    await nextTick();
    window.scrollTo(0, 0);
}

// The mobile tab bar is hidden while full-screen chat mode is active (see
// activateChat above), so without this there'd be no way back to the hero
// view / other tabs before Kaia has produced an itinerary — only a visible
// "Back" control (wired to this in the template) can get the user out.
function collapseChat() {
    chatActivated = false;
    emit('chat-active', false);
}

function lockBodyScrollForMobileKeyboard() {
    if (!window.matchMedia('(hover: none), (pointer: coarse)').matches) {
        return;
    }

    // In full-screen chat mode (see activateChat above) the input is
    // already pinned at the bottom of a column sized to exactly fill the
    // viewport — there's nothing to scroll into view, and doing so anyway
    // would fight the scroll-to-0 that just ran. Outside that mode (a plan
    // already exists, or this is the non-fullscreen embedded chat), bring
    // the input row into view *before* freezing scroll below: once the body
    // is taken out of flow there's no scrollable document left for iOS's
    // native "scroll the focused input above the keyboard" behavior to act
    // on, so if we freeze at whatever position the page already happened to
    // be at, an input sitting in the lower half of the screen ends up
    // permanently covered by the keyboard with no way to reach it. Aligning
    // it to the top — comfortably clear of the fixed header via the
    // scroll-margin-top on `.chat-input-row input` — keeps it well above
    // where a keyboard (which only ever eats the bottom portion of the
    // screen) can reach, regardless of where the page was scrolled to.
    const kaiaPage = document.querySelector('.kaia-page');
    const isFullscreenChat =
        kaiaPage?.classList.contains('chat-fullscreen') &&
        !kaiaPage?.classList.contains('has-plan');

    if (!isFullscreenChat) {
        chatInput.value?.scrollIntoView({ block: 'start' });
    }

    savedBodyScrollY = window.scrollY;
    document.body.style.position = 'fixed';
    document.body.style.top = `-${savedBodyScrollY}px`;
    document.body.style.left = '0';
    document.body.style.right = '0';
}

// activateChat's scroll-to-0 (for entering full-screen chat mode) has to
// finish *before* lockBodyScrollForMobileKeyboard reads window.scrollY —
// otherwise the lock freezes the page at its old, pre-fullscreen offset.
async function onInputFocus() {
    await activateChat();
    lockBodyScrollForMobileKeyboard();
}

function unlockBodyScroll() {
    if (document.body.style.position !== 'fixed') {
        return;
    }

    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    window.scrollTo(0, savedBodyScrollY);
}

function pinLogToBottom() {
    if (chatLog.value) {
        chatLog.value.scrollTop = chatLog.value.scrollHeight;
    }
}

function syncScroll() {
    pinLogToBottom();

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

// The thinking status rotates every couple of seconds and the longer ones
// wrap onto a second line — which grows the log below the scroll position
// nobody touched since the indicator appeared, so the line the traveler is
// meant to read is the one hidden under the input row. Re-pin on every
// rotation. Log only, deliberately: the panel itself hasn't moved, and
// nudging the whole page every 2.5 seconds while waiting would be worse
// than the clipped line.
watch(thinkingIndex, async () => {
    await nextTick();
    pinLogToBottom();
});

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
        messages.value.push({
            role: 'ai',
            text: result.text,
            awaiting: result.awaiting ?? null,
        });
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
    } else {
        // Anything else is a payload we do not understand, and the one thing
        // it must never be is "handled". Returning true here is what let a
        // rejected request end the turn in silence — see the comment in
        // sendKaiaMessage.
        return false;
    }

    return true;
}

// A rate limit is the only refusal that comes with an expiry, so the chat
// waits it out instead of asking the traveler to. Once per turn: if the window
// reopens and the very next request is refused again, the wait was not the
// whole story, and counting down a second time would just be a slower dead
// end — so that one hands the button back.
let countdown: ReturnType<typeof setInterval> | null = null;

function stopCountdown() {
    if (countdown !== null) {
        clearInterval(countdown);
        countdown = null;
    }

    resumeIn.value = null;
}

// Drops a countdown bubble the traveler has overtaken — by typing, tapping a
// suggestion or hitting Retry — so the log never shows a wait that nothing is
// waiting for any more.
function cancelWait() {
    stopCountdown();

    if (lastMessage.value?.failedAction === 'wait') {
        messages.value.pop();
    }
}

function waitOutRateLimit(seconds: number) {
    stopCountdown();

    resumeIn.value = seconds;
    messages.value.push({
        role: 'ai',
        text: t('chat.errorBusy'),
        failed: true,
        failedAction: 'wait',
    });

    void scrollToBottom();

    countdown = setInterval(() => {
        const left = (resumeIn.value ?? 0) - 1;

        if (left > 0) {
            resumeIn.value = left;

            return;
        }

        cancelWait();
        void runKaiaRequest(false, false);
    }, 1000);
}

/**
 * `mayWait` is false on the attempt that a countdown itself started — see
 * waitOutRateLimit above.
 */
async function requestKaiaReply(mayWait = true) {
    // History Kaia should actually see — a previous failed-attempt bubble
    // never happened as far as the conversation is concerned.
    const history = messages.value.filter((m) => !m.failed);

    for (let attempt = 0; attempt <= MAX_SILENT_RETRIES; attempt++) {
        try {
            const result = await sendKaiaMessage(history);

            if (applyResult(result)) {
                return;
            }
        } catch (e) {
            // A refusal that will refuse again — an expired session, a spent
            // rate limit — is worth saying now rather than three attempts
            // from now, and it needs the action that actually fixes it.
            if (e instanceof KaiaRequestError && !e.retryable) {
                if (e.status === 429 && mayWait && e.retryAfter) {
                    waitOutRateLimit(e.retryAfter);

                    return;
                }

                messages.value.push({
                    role: 'ai',
                    text: e.reload
                        ? t('chat.errorSession')
                        : e.status === 429
                          ? t('chat.errorBusy')
                          : t('chat.error'),
                    failed: true,
                    failedAction: e.reload ? 'reload' : 'retry',
                });

                return;
            }

            // Anything else — a network error, a 5xx — falls through to the
            // retry/backoff below.
        }

        if (attempt < MAX_SILENT_RETRIES) {
            await wait(1000 * (attempt + 1));
        }
    }

    messages.value.push({ role: 'ai', text: t('chat.error'), failed: true });
}

// `refocus` is false for a tapped answer, and that is the whole point of the
// tap-through flow on a phone: focusing the input opens the on-screen
// keyboard, which then covers the next set of buttons. Someone who is typing
// still gets focus back — the input is disabled while Kaia is "typing", so it
// would otherwise be lost after every turn.
async function runKaiaRequest(refocus = true, mayWait = true) {
    isTyping.value = true;
    startThinking();
    await scrollToBottom();

    await requestKaiaReply(mayWait);

    stopThinking();
    isTyping.value = false;

    await scrollToBottom();

    if (!refocus) {
        return;
    }

    await nextTick();
    chatInput.value?.focus();
}

async function sendMessage() {
    const text = inputText.value.trim();

    if (!text || chatBusy.value) {
        return;
    }

    messages.value.push({ role: 'user', text });
    inputText.value = '';

    await runKaiaRequest();
}

// A tapped answer is an ordinary user turn — same text, same history, same
// request. Nothing about the conversation knows it was a button, which is what
// keeps typing and tapping mixable in one session.
async function pickSuggestion(text: string) {
    if (chatBusy.value) {
        return;
    }

    // Mobile enters full-screen chat on input focus; a traveler who never
    // touches the input would otherwise plan a whole trip in the small
    // hero-sized panel.
    await activateChat();

    messages.value.push({ role: 'user', text });
    inputText.value = '';

    await runKaiaRequest(false);
}

// The only thing that fixes an expired session: a fresh page mints a fresh
// CSRF token. The conversation so far is lost, which is the honest trade —
// the alternative on offer was a chat that answered nothing at all.
function reloadPage() {
    window.location.reload();
}

async function retryLastMessage() {
    if (isTyping.value) {
        return;
    }

    // A traveler who taps Retry has overtaken any countdown that is running,
    // and re-sends at their own risk — so this attempt does not start another.
    const wasWaiting = resumeIn.value !== null;

    cancelWait();

    const last = messages.value[messages.value.length - 1];

    if (last?.failed) {
        messages.value.pop();
    }

    await runKaiaRequest(true, !wasWaiting);
}
</script>

<template>
    <!-- The header floats on the hero rather than sitting above it, so the
         photograph runs all the way to the top of the screen. It turns back
         into a solid bar on the first scroll. -->
    <SiteHeader overlay />
    <div
        id="kaia-hero"
        class="hero has-overlay-nav"
        :class="{ 'has-photo': photo }"
    >
        <!-- A real Namibian landscape, when one is configured — see
             config/hero.php. It replaces the illustration below rather than
             sitting behind it, so the drawn dunes, tree and sun are not
             rendered at all while a photo is up. Eager and high priority on
             purpose: it is the first thing above the fold, and lazy-loading
             the largest element on the page only makes it arrive late. -->
        <img
            v-if="photo"
            class="hero-photo"
            :src="photo.url"
            :style="{ objectPosition: photo.focus }"
            alt=""
            aria-hidden="true"
            fetchpriority="high"
            decoding="async"
        />
        <div
            v-if="photo"
            class="hero-photo-scrim"
            :class="`hero-photo-scrim-${photo.scrim}`"
            aria-hidden="true"
        ></div>
        <svg
            v-if="!photo"
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
        <!-- Kept out of the hero-bg SVG above on purpose: hero-bg stretches
             non-uniformly to fill the hero at any height, which is fine for
             the abstract dune shapes but would squash a recognisable
             silhouette like this whenever the hero gets much taller than its
             340-unit viewBox (e.g. the mobile full-height state). DeadTree
             keeps its own aspect-ratio-preserving viewBox. -->
        <DeadTree v-if="!photo" class="hero-tree" />
        <div class="hero-content">
            <div class="hero-head">
                <h1>{{ t('hero.title') }}</h1>
                <p>{{ t('hero.subtitle') }}</p>
            </div>

            <div v-if="!photo" class="hero-sun" aria-hidden="true"></div>

            <div class="chat-panel" ref="chatPanel" @click="activateChat">
                <button
                    type="button"
                    class="chat-fullscreen-back"
                    :aria-label="
                        hasPlan ? t('chat.backToPlan') : t('chat.back')
                    "
                    @click.stop="collapseChat"
                >
                    <svg
                        viewBox="0 0 24 24"
                        width="18"
                        height="18"
                        aria-hidden="true"
                    >
                        <path
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M15 18l-6-6 6-6"
                        />
                    </svg>
                    {{ hasPlan ? t('chat.backToPlan') : t('chat.back') }}
                </button>
                <!-- Who the traveller is talking to. Kaia is a name in
                     running text everywhere else in the product; this is the
                     one place she is actually introduced, so it sits above
                     the conversation rather than beside every message —
                     an avatar per bubble would eat the width the text needs
                     on a phone. See BRAND.md. -->
                <div class="chat-head">
                    <KaiaAvatar class="chat-head-avatar" />
                    <div class="chat-head-names">
                        <KaiaWordmark class="chat-head-wordmark" />
                        <span class="chat-head-role">{{
                            t('chat.headRole')
                        }}</span>
                    </div>
                </div>
                <div class="chat-log" ref="chatLog">
                    <div
                        v-for="(msg, i) in messages"
                        :key="i"
                        :class="['msg', msg.role, { failed: msg.failed }]"
                    >
                        {{ msg.text }}
                        <template v-if="msg.failed">
                            <br />
                            <!-- A window that reopens on its own gets a
                                 countdown instead of a button: there is
                                 nothing for the traveler to do, and the one
                                 thing they would do — tap Retry — is what
                                 keeps the window shut. -->
                            <span
                                v-if="msg.failedAction === 'wait'"
                                class="chat-resume-in"
                            >
                                {{
                                    t('chat.errorBusyResume', {
                                        seconds: resumeIn ?? 0,
                                    })
                                }}
                            </span>
                            <button
                                v-else-if="msg.failedAction === 'reload'"
                                type="button"
                                class="chat-retry-btn"
                                @click="reloadPage"
                            >
                                {{ t('chat.reload') }}
                            </button>
                            <button
                                v-else
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
                                v-bind="
                                    thumbAttrs(
                                        recommendationImage(msg.recommendation),
                                        90,
                                    )
                                "
                                :alt="msg.recommendation.name"
                                class="chat-rec-img"
                                width="90"
                                height="90"
                                loading="lazy"
                                decoding="async"
                                @error="
                                    onImageError(
                                        $event,
                                        msg.recommendation.type,
                                    )
                                "
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
                    <ChatSuggestions
                        :suggestions="suggestions"
                        :hint="suggestionsHint"
                        :disabled="chatBusy"
                        @pick="pickSuggestion"
                    />
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
                        @focus="onInputFocus"
                        @blur="unlockBodyScroll"
                    />
                    <button
                        v-if="isVoiceSupported"
                        type="button"
                        class="chat-mic-btn"
                        :class="{ listening: isListening }"
                        :disabled="chatBusy"
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
                        :disabled="chatBusy || !inputText.trim()"
                        @click="sendMessage"
                    >
                        {{ t('chat.send') }}
                    </button>
                </div>
            </div>
        </div>
        <!-- Attribution, not decoration: most stock licences require it, and
             a credit that only appears in a config file is not a credit. -->
        <p v-if="photo?.credit" class="hero-photo-credit">
            {{ t('hero.photoCredit', { credit: photo.credit }) }}
        </p>
    </div>
</template>
