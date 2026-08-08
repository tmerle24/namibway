<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import batch from '@/routes/inquiries/batch';

const props = defineProps<{
    items: Map<number, string>;
}>();

const emit = defineEmits<{
    remove: [id: number];
    clear: [];
}>();

const { t } = useI18n();

const formOpen = ref(false);

// A shortlist request is a booking request, and those need an account
// (inquiries.batch.store is behind `auth`). Show that up front instead of a
// form whose submit would bounce to the login page and lose the shortlist.
const account = computed(
    () =>
        (
            usePage().props.auth as
                { user: { name?: string; email?: string } | null } | undefined
        )?.user ?? null,
);

function loginUrl(): string {
    return `/login/start?redirect=${encodeURIComponent(
        window.location.pathname + window.location.search,
    )}`;
}
</script>

<template>
    <div v-if="props.items.size > 0" class="shortlist-bar">
        <div class="shortlist-summary" @click="formOpen = !formOpen">
            <span class="shortlist-count">{{
                t('explore.shortlist.count', { count: props.items.size })
            }}</span>
            <span class="shortlist-names">{{
                [...props.items.values()].join(', ')
            }}</span>
            <button type="button" class="shortlist-cta">
                {{
                    formOpen
                        ? t('explore.shortlist.hide')
                        : t('explore.shortlist.request')
                }}
            </button>
        </div>
        <div v-if="formOpen" class="shortlist-panel">
            <div v-if="!account" class="shortlist-login">
                <p>{{ t('explore.shortlist.loginRequired') }}</p>
                <a :href="loginUrl()" class="cta">{{
                    t('explore.shortlist.login')
                }}</a>
            </div>
            <Form
                v-else
                v-bind="batch.store.form()"
                reset-on-success
                :transform="
                    (data) => ({
                        ...data,
                        listing_ids: [...props.items.keys()],
                    })
                "
                v-slot="{ errors, processing, recentlySuccessful }"
                class="shortlist-form"
                @success="() => emit('clear')"
            >
                <label>
                    {{ t('explore.shortlist.name') }}
                    <input
                        name="name"
                        type="text"
                        required
                        :value="account.name"
                    />
                    <InputError :message="errors.name" />
                </label>
                <label>
                    {{ t('explore.shortlist.email') }}
                    <input
                        name="email"
                        type="email"
                        required
                        :value="account.email"
                    />
                    <InputError :message="errors.email" />
                </label>
                <label>
                    {{ t('explore.shortlist.phone') }}
                    <input name="phone" type="text" />
                    <InputError :message="errors.phone" />
                </label>
                <label>
                    {{ t('explore.shortlist.message') }}
                    <textarea name="message" rows="2"></textarea>
                    <InputError :message="errors.message" />
                </label>
                <InputError :message="errors.listing_ids" />
                <button type="submit" class="cta" :disabled="processing">
                    {{
                        processing
                            ? t('explore.shortlist.sending')
                            : t('explore.shortlist.send')
                    }}
                </button>
                <p v-if="recentlySuccessful" class="confirm-note">
                    {{ t('explore.shortlist.success') }}
                </p>
            </Form>
        </div>
    </div>
</template>

<style scoped>
.shortlist-bar {
    position: fixed;
    left: 50%;
    bottom: 20px;
    transform: translateX(-50%);
    width: min(720px, calc(100% - 32px));
    background: var(--paper, #fbf8f1);
    border: 1px solid var(--sand-dark, #d6c9b5);
    border-radius: 14px;
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.18);
    z-index: 40;
}

.shortlist-summary {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    cursor: pointer;
}

.shortlist-count {
    font-weight: 700;
    white-space: nowrap;
}

.shortlist-names {
    flex: 1;
    font-size: 13px;
    color: #6b5f54;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.shortlist-cta {
    border: none;
    border-radius: 999px;
    padding: 8px 16px;
    background: var(--rust, #b5651d);
    color: #fff;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;
}

.shortlist-panel {
    border-top: 1px solid var(--sand-dark, #d6c9b5);
    padding: 16px;
    max-height: 60vh;
    overflow-y: auto;
}

.shortlist-form {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.shortlist-form label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    font-weight: 600;
}

.shortlist-form input,
.shortlist-form textarea {
    font: inherit;
    padding: 8px 10px;
    border: 1px solid var(--sand-dark, #d6c9b5);
    border-radius: 8px;
}

.shortlist-form .cta {
    align-self: flex-start;
    border: none;
    border-radius: 999px;
    padding: 10px 20px;
    background: var(--rust, #b5651d);
    color: #fff;
    font-weight: 600;
    cursor: pointer;
}

.shortlist-form .cta:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.shortlist-login {
    display: flex;
    flex-direction: column;
    gap: 12px;
    font-size: 13px;
    color: #5b5346;
}

.shortlist-login .cta {
    align-self: flex-start;
    border-radius: 999px;
    padding: 10px 20px;
    background: var(--rust, #b5651d);
    color: #fff;
    font-weight: 600;
    text-decoration: none;
}

.confirm-note {
    font-size: 13px;
    color: #2f6b3f;
    font-weight: 600;
}
</style>
