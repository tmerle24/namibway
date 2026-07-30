<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'authenticated'): void;
}>();

const { t } = useI18n();

type Tab = 'login' | 'register';
const tab = ref<Tab>('login');

// --- Login form ---
const loginEmail = ref('');
const loginPassword = ref('');
const loginError = ref('');
const loginLoading = ref(false);

async function submitLogin() {
    if (loginLoading.value) {
        return;
    }

    loginError.value = '';
    loginLoading.value = true;

    try {
        const res = await fetch('/login', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ email: loginEmail.value, password: loginPassword.value }),
        });

        if (res.ok) {
            // Reload only the auth prop — Vue state stays intact
            router.reload({ only: ['auth'], onSuccess: () => emit('authenticated') });
        } else {
            const data = await res.json().catch(() => ({}));
            loginError.value = flattenErrors(data) || t('saveModal.loginFailed');
        }
    } catch {
        loginError.value = t('saveModal.networkError');
    } finally {
        loginLoading.value = false;
    }
}

// --- Register form ---
const regName = ref('');
const regEmail = ref('');
const regPassword = ref('');
const regConfirm = ref('');
const regError = ref('');
const regLoading = ref(false);

async function submitRegister() {
    if (regLoading.value) {
        return;
    }

    regError.value = '';
    regLoading.value = true;

    try {
        const res = await fetch('/register', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                name: regName.value,
                email: regEmail.value,
                password: regPassword.value,
                password_confirmation: regConfirm.value,
            }),
        });

        if (res.ok) {
            router.reload({ only: ['auth'], onSuccess: () => emit('authenticated') });
        } else {
            const data = await res.json().catch(() => ({}));
            regError.value = flattenErrors(data) || t('saveModal.registerFailed');
        }
    } catch {
        regError.value = t('saveModal.networkError');
    } finally {
        regLoading.value = false;
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function flattenErrors(data: unknown): string {
    if (typeof data !== 'object' || data === null) {
        return '';
    }

    const d = data as Record<string, unknown>;

    if (d.errors && typeof d.errors === 'object') {
        return Object.values(d.errors as Record<string, string[]>).flat().join(' ');
    }

    if (typeof d.message === 'string') {
        return d.message;
    }

    return '';
}
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <div class="modal-box" role="dialog" aria-modal="true">
            <button class="modal-close" :aria-label="t('saveModal.close')" @click="emit('close')">×</button>

            <h3>{{ t('saveModal.title') }}</h3>
            <p class="modal-sub">{{ t('saveModal.subtitle') }}</p>

            <div class="modal-tabs">
                <button :class="['tab-btn', { active: tab === 'login' }]" @click="tab = 'login'">
                    {{ t('saveModal.tabLogin') }}
                </button>
                <button :class="['tab-btn', { active: tab === 'register' }]" @click="tab = 'register'">
                    {{ t('saveModal.tabRegister') }}
                </button>
            </div>

            <!-- Login tab -->
            <form v-if="tab === 'login'" class="modal-form" @submit.prevent="submitLogin">
                <input
                    v-model="loginEmail"
                    type="email"
                    :placeholder="t('saveModal.email')"
                    autocomplete="email"
                    required
                />
                <input
                    v-model="loginPassword"
                    type="password"
                    :placeholder="t('saveModal.password')"
                    autocomplete="current-password"
                    required
                />
                <p v-if="loginError" class="form-error">{{ loginError }}</p>
                <button type="submit" class="modal-submit" :disabled="loginLoading">
                    {{ loginLoading ? t('saveModal.loggingIn') : t('saveModal.loginCta') }}
                </button>
            </form>

            <!-- Register tab -->
            <form v-else class="modal-form" @submit.prevent="submitRegister">
                <input
                    v-model="regName"
                    type="text"
                    :placeholder="t('saveModal.name')"
                    autocomplete="name"
                    required
                />
                <input
                    v-model="regEmail"
                    type="email"
                    :placeholder="t('saveModal.email')"
                    autocomplete="email"
                    required
                />
                <input
                    v-model="regPassword"
                    type="password"
                    :placeholder="t('saveModal.password')"
                    autocomplete="new-password"
                    required
                />
                <input
                    v-model="regConfirm"
                    type="password"
                    :placeholder="t('saveModal.passwordConfirm')"
                    autocomplete="new-password"
                    required
                />
                <p v-if="regError" class="form-error">{{ regError }}</p>
                <button type="submit" class="modal-submit" :disabled="regLoading">
                    {{ regLoading ? t('saveModal.registering') : t('saveModal.registerCta') }}
                </button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(16, 26, 48, 0.55);
    z-index: 200;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-box {
    background: var(--paper, #fbf8f1);
    border-radius: 16px;
    padding: 32px;
    max-width: 400px;
    width: 100%;
    position: relative;
    box-shadow: 0 24px 60px rgba(16, 26, 48, 0.3);
}
.modal-close {
    position: absolute;
    top: 14px;
    right: 16px;
    background: none;
    border: none;
    font-size: 22px;
    cursor: pointer;
    color: #8a7f68;
    line-height: 1;
}
.modal-close:hover { color: var(--ink, #241c15); }
h3 {
    font-family: 'Fraunces', serif;
    font-size: 20px;
    font-weight: 500;
    margin: 0 0 6px;
    color: var(--ink, #241c15);
}
.modal-sub {
    font-size: 13.5px;
    color: #5b5346;
    margin: 0 0 20px;
}
.modal-tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--sand-dark, #ded2b8);
    margin-bottom: 20px;
}
.tab-btn {
    background: none;
    border: none;
    padding: 8px 14px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    color: #8a7f68;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: color 0.15s, border-color 0.15s;
}
.tab-btn.active {
    color: var(--rust, #b5651d);
    border-bottom-color: var(--rust, #b5651d);
}
.modal-form {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.modal-form input {
    padding: 10px 12px;
    border: 1px solid var(--sand-dark, #ded2b8);
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    background: var(--paper, #fbf8f1);
    color: var(--ink, #241c15);
}
.modal-form input:focus {
    outline: 2px solid var(--rust, #b5651d);
    outline-offset: 1px;
}
.form-error {
    font-size: 13px;
    color: #b02a2a;
    margin: 0;
}
.modal-submit {
    margin-top: 4px;
    padding: 11px;
    background: var(--night, #1b2a4a);
    color: var(--paper, #fbf8f1);
    border: none;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 600;
    font-family: 'Inter', sans-serif;
    cursor: pointer;
    transition: background 0.15s;
}
.modal-submit:hover:not(:disabled) { background: var(--night-deep, #101a30); }
.modal-submit:disabled { opacity: 0.5; cursor: default; }
</style>
