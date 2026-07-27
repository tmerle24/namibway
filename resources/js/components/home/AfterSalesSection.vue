<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t, tm } = useI18n();

const checklistItems = tm('afterSales.checklist.items') as unknown as string[];

const supportText = ref('');
const supportNote = ref('');
function sendSupport() {
    if (!supportText.value.trim()) {
        return;
    }

    supportNote.value = t('afterSales.support.confirm');
}

const feedbackText = ref('');
const feedbackNote = ref('');
const rating = ref(0);
function publishFeedback() {
    feedbackNote.value = t('afterSales.feedback.confirm');
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
                <textarea
                    v-model="supportText"
                    :placeholder="t('afterSales.support.placeholder')"
                ></textarea>
                <button class="small" @click="sendSupport">
                    {{ t('afterSales.support.send') }}
                </button>
                <div v-if="supportNote" class="confirm-note">
                    {{ supportNote }}
                </div>
            </div>
            <div class="as-card">
                <h3>{{ t('afterSales.feedback.title') }}</h3>
                <p>{{ t('afterSales.feedback.subtitle') }}</p>
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
                <button class="small" @click="publishFeedback">
                    {{ t('afterSales.feedback.publish') }}
                </button>
                <div v-if="feedbackNote" class="confirm-note">
                    {{ feedbackNote }}
                </div>
            </div>
        </div>
    </section>
</template>
