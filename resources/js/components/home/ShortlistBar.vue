<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import batch from '@/routes/inquiries/batch';
import SaveLoginModal from './SaveLoginModal.vue';

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
// (inquiries.batch.store is behind `auth`). Asked for in a modal at the moment
// of sending — a redirect to the login page would take the shortlist, which
// lives in the Explore page's local state, down with it.
const account = computed(
    () =>
        (
            usePage().props.auth as
                { user: { name?: string; email?: string } | null } | undefined
        )?.user ?? null,
);

// One-shot, so logging in doesn't overwrite what was already typed here.
const initialAccount = account.value;

const shortlistForm = ref<{ submit: () => void } | null>(null);
const showLogin = ref(false);

function onSend(event: MouseEvent) {
    // Logged in: the button is a real submit and this does nothing.
    if (account.value) {
        return;
    }

    // type="button" skips the browser's own required-field check, so run it by
    // hand — otherwise an empty form sends the traveler through a login only to
    // come back with validation errors.
    const form = (event.currentTarget as HTMLElement).closest('form');

    if (form && !form.reportValidity()) {
        return;
    }

    showLogin.value = true;
}

async function onAuthenticated() {
    showLogin.value = false;

    await nextTick();
    shortlistForm.value?.submit();
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
            <Form
                ref="shortlistForm"
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
                        :value="initialAccount?.name"
                    />
                    <InputError :message="errors.name" />
                </label>
                <label>
                    {{ t('explore.shortlist.email') }}
                    <input
                        name="email"
                        type="email"
                        required
                        :value="initialAccount?.email"
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
                <!--
                    type="button" while logged out, so the click opens the login
                    modal instead of firing a request the server would only
                    bounce. Everything typed here stays put and is sent as soon
                    as the modal reports back.
                -->
                <button
                    :type="account ? 'submit' : 'button'"
                    class="cta"
                    :disabled="processing"
                    @click="onSend"
                >
                    {{
                        processing
                            ? t('explore.shortlist.sending')
                            : t('explore.shortlist.send')
                    }}
                </button>
                <p v-if="!account" class="shortlist-login-note">
                    {{ t('explore.shortlist.loginRequired') }}
                </p>
                <p v-if="recentlySuccessful" class="confirm-note">
                    {{ t('explore.shortlist.success') }}
                </p>
            </Form>
        </div>

        <SaveLoginModal
            v-if="showLogin"
            intent="book"
            @close="showLogin = false"
            @authenticated="onAuthenticated"
        />
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

/* Heads-up under the send button while logged out — the login itself happens
   in a modal on send, so this isn't the call to action. */
.shortlist-login-note {
    font-size: 12px;
    color: #5b5346;
}

.confirm-note {
    font-size: 13px;
    color: #2f6b3f;
    font-weight: 600;
}
</style>
