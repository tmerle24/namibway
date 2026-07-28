<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { FeedbackPayload, SupportPayload } from '@/lib/kaia-client';
import { sendFeedback, sendSupport } from '@/lib/kaia-client';

const props = defineProps<{
    guestName?: string | null;
    guestEmail?: string | null;
    tripId?: number | null;
}>();

const { t, tm } = useI18n();

const checklistItems = tm('afterSales.checklist.items') as unknown as string[];

const supportText = ref('');
const supportName = ref(props.guestName ?? '');
const supportEmail = ref(props.guestEmail ?? '');
const supportNote = ref('');
const supportError = ref('');
const supportLoading = ref(false);

async function sendSupportMessage() {
    if (!supportText.value.trim() || !supportEmail.value.trim()) {
        return;
    }

    supportLoading.value = true;
    supportError.value = '';

    try {
        const payload: SupportPayload = {
            name: supportName.value || 'Anonymous',
            email: supportEmail.value,
            message: supportText.value,
            trip_id: props.tripId ?? null,
        };

        await sendSupport(payload);
        supportNote.value = t('afterSales.support.confirm');
        supportText.value = '';
    } catch {
        supportError.value = t('afterSales.support.error');
    } finally {
        supportLoading.value = false;
    }
}

function doPrint() {
    window.print();
}

const feedbackText = ref('');
const feedbackNote = ref('');
const feedbackError = ref('');
const feedbackLoading = ref(false);
const rating = ref(0);

async function publishFeedback() {
    if (!feedbackText.value.trim()) {
        return;
    }

    feedbackLoading.value = true;
    feedbackError.value = '';

    try {
        const payload: FeedbackPayload = {
            name: props.guestName ?? null,
            message: feedbackText.value,
            rating: rating.value > 0 ? rating.value : null,
            trip_id: props.tripId ?? null,
        };

        await sendFeedback(payload);
        feedbackNote.value = t('afterSales.feedback.confirm');
        feedbackText.value = '';
        rating.value = 0;
    } catch {
        feedbackError.value = t('afterSales.feedback.error');
    } finally {
        feedbackLoading.value = false;
    }
}

function printChecklist() {
    window.print();
}
</script>

<template>
    <section>
        <div class="section-head">
            <div class="eyebrow">{{ t('afterSales.eyebrow') }}</div>
            <h2>{{ t('afterSales.title') }}</h2>
            <p>{{ t('afterSales.subtitle') }}</p>
        </div>
        <div class="aftersales-grid">
            <div class="as-card">
                <h3>{{ t('afterSales.checklist.title') }}</h3>
                <p>{{ t('afterSales.checklist.subtitle') }}</p>
                <div class="checklist">
                    <label v-for="(item, index) in checklistItems" :key="index">
                        <input type="checkbox" />{{ item }}
                    </label>
                </div>
                <button class="small" @click="printChecklist()">
                    {{ t('afterSales.checklist.print') }}
                </button>
            </div>

            <div class="as-card">
                <h3>{{ t('afterSales.support.title') }}</h3>
                <p>{{ t('afterSales.support.subtitle') }}</p>
                <template v-if="!supportNote">
                    <input
                        v-if="!guestName"
                        v-model="supportName"
                        type="text"
                        :placeholder="t('afterSales.support.namePlaceholder')"
                    />
                    <input
                        v-if="!guestEmail"
                        v-model="supportEmail"
                        type="email"
                        :placeholder="t('afterSales.support.emailPlaceholder')"
                    />
                    <textarea
                        v-model="supportText"
                        :placeholder="t('afterSales.support.placeholder')"
                    ></textarea>
                    <p v-if="supportError" class="form-error">
                        {{ supportError }}
                    </p>
                    <button
                        class="small"
                        :disabled="supportLoading"
                        @click="sendSupportMessage"
                    >
                        {{
                            supportLoading
                                ? t('afterSales.support.sending')
                                : t('afterSales.support.send')
                        }}
                    </button>
                </template>
                <div v-else class="confirm-note">{{ supportNote }}</div>
            </div>

            <div class="as-card">
                <h3>{{ t('afterSales.feedback.title') }}</h3>
                <p>{{ t('afterSales.feedback.subtitle') }}</p>
                <template v-if="!feedbackNote">
                    <div class="stars">
                        <span
                            v-for="n in 5"
                            :key="n"
                            :class="{ active: n <= rating }"
                            @click="rating = n"
                            >★</span
                        >
                    </div>
                    <textarea
                        v-model="feedbackText"
                        :placeholder="t('afterSales.feedback.placeholder')"
                    ></textarea>
                    <p v-if="feedbackError" class="form-error">
                        {{ feedbackError }}
                    </p>
                    <button
                        class="small"
                        :disabled="feedbackLoading"
                        @click="publishFeedback"
                    >
                        {{
                            feedbackLoading
                                ? t('afterSales.feedback.sending')
                                : t('afterSales.feedback.publish')
                        }}
                    </button>
                </template>
                <div v-else class="confirm-note">{{ feedbackNote }}</div>
            </div>
        </div>
    </section>
</template>
