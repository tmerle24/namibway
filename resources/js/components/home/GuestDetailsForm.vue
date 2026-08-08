<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { GuestDetails, ItineraryVariant } from '@/lib/kaia-types';

const props = defineProps<{
    variant: ItineraryVariant;
    loading?: boolean;
    error?: string | null;
}>();

const emit = defineEmits<{
    submit: [details: GuestDetails];
}>();

const { t } = useI18n();

// Kaia formats dates as "D Mon YYYY" (e.g. "9 Aug 2026"). Parse that explicitly
// rather than relying on Date() which handles this format inconsistently across browsers.
const MONTH_MAP: Record<string, number> = {
    Jan: 0,
    Feb: 1,
    Mar: 2,
    Apr: 3,
    May: 4,
    Jun: 5,
    Jul: 6,
    Aug: 7,
    Sep: 8,
    Oct: 9,
    Nov: 10,
    Dec: 11,
};

function parseDateToIso(dateStr: string | null | undefined): string {
    if (!dateStr) {
        return '';
    }

    const m = /^(\d{1,2})\s+([A-Za-z]{3})\s+(\d{4})$/.exec(dateStr);

    if (m) {
        const month = MONTH_MAP[m[2]];

        if (month !== undefined) {
            return `${m[3]}-${String(month + 1).padStart(2, '0')}-${m[1].padStart(2, '0')}`;
        }
    }

    // Fallback for ISO or other parseable formats
    const d = new Date(dateStr);

    if (isNaN(d.getTime())) {
        return '';
    }

    return d.toISOString().slice(0, 10);
}

function deriveCheckOut(variant: ItineraryVariant): string {
    const lastDay = variant.days.at(-1);

    if (!lastDay?.date) {
        return '';
    }

    const isoStr = parseDateToIso(lastDay.date);

    if (!isoStr) {
        return '';
    }

    const [y, mo, da] = isoStr.split('-').map(Number);
    const d = new Date(y, mo - 1, da + 1);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// Booking requires an account, so the two fields the account already knows
// shouldn't be retyped. Still editable: the traveler booking may want the
// confirmation to go to a different address than the one they log in with.
const account = computed(
    () =>
        (
            usePage().props.auth as
                { user: { name?: string; email?: string } | null } | undefined
        )?.user ?? null,
);

const name = ref(account.value?.name ?? '');
const email = ref(account.value?.email ?? '');
const phone = ref('');
const checkIn = ref(parseDateToIso(props.variant.days[0]?.date));
const checkOut = ref(deriveCheckOut(props.variant));
const adults = ref(2);
const children = ref(0);

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function submit() {
    emit('submit', {
        name: name.value,
        email: email.value,
        phone: phone.value,
        check_in: checkIn.value,
        check_out: checkOut.value,
        adults: adults.value,
        children: children.value,
    });
}
</script>

<template>
    <section id="guest-form-section" class="guest-form-section">
        <div class="section-head">
            <div class="eyebrow">{{ t('guestForm.eyebrow') }}</div>
            <h2>{{ t('guestForm.title') }}</h2>
            <p>{{ t('guestForm.subtitle') }}</p>
        </div>

        <form class="guest-form" @submit.prevent="submit">
            <div class="form-row">
                <label>
                    {{ t('guestForm.name') }}
                    <input
                        v-model="name"
                        type="text"
                        required
                        autocomplete="name"
                        :placeholder="t('guestForm.namePlaceholder')"
                    />
                </label>
                <label>
                    {{ t('guestForm.email') }}
                    <input
                        v-model="email"
                        type="email"
                        required
                        autocomplete="email"
                        :placeholder="t('guestForm.emailPlaceholder')"
                    />
                </label>
            </div>

            <div class="form-row">
                <label>
                    {{ t('guestForm.phone') }}
                    <input
                        v-model="phone"
                        type="tel"
                        autocomplete="tel"
                        :placeholder="t('guestForm.phonePlaceholder')"
                    />
                </label>
            </div>

            <div class="form-row">
                <label>
                    {{ t('guestForm.checkIn') }}
                    <input
                        v-model="checkIn"
                        type="date"
                        required
                        :min="today()"
                    />
                </label>
                <label>
                    {{ t('guestForm.checkOut') }}
                    <input
                        v-model="checkOut"
                        type="date"
                        required
                        :min="checkIn || today()"
                    />
                </label>
            </div>

            <div class="form-row">
                <label>
                    {{ t('guestForm.adults') }}
                    <input
                        v-model.number="adults"
                        type="number"
                        min="1"
                        max="20"
                        required
                    />
                </label>
                <label>
                    {{ t('guestForm.children') }}
                    <input
                        v-model.number="children"
                        type="number"
                        min="0"
                        max="20"
                    />
                </label>
            </div>

            <p v-if="error" class="form-error">{{ error }}</p>

            <button type="submit" class="cta-btn" :disabled="loading">
                <span v-if="loading">{{ t('guestForm.sending') }}</span>
                <span v-else>{{ t('guestForm.submit') }}</span>
            </button>

            <p class="form-note">{{ t('guestForm.note') }}</p>
        </form>
    </section>
</template>
