<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { TripParams } from '@/lib/kaia-types';

export interface TripParamsFormValues {
    dateFrom: string;
    dateTo: string;
    adults: number;
    childrenAges: string;
    interests: string;
    budgetTier: string;
    startLocation: string;
    endLocation: string;
}

const props = defineProps<{
    tripParams?: TripParams | null;
    startLocation: string;
    endLocation: string;
    referenceStartDate: Date | null;
    locationSuggestions: string[];
    saving: boolean;
    error: string | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'save', values: TripParamsFormValues): void;
}>();

const { t } = useI18n();

function toDateInputValue(date: Date | null): string {
    if (!date) {
        return '';
    }

    return date.toISOString().slice(0, 10);
}

function addDays(date: Date, days: number): Date {
    const result = new Date(date);
    result.setDate(result.getDate() + days);

    return result;
}

const initialFrom =
    toDateInputValue(props.referenceStartDate) || toDateInputValue(new Date());
const initialNights = props.tripParams?.nights ?? 7;
const initialTo = toDateInputValue(
    addDays(props.referenceStartDate ?? new Date(), Math.max(initialNights, 1)),
);

const dateFrom = ref(initialFrom);
const dateTo = ref(initialTo);
const adults = ref(props.tripParams?.adults ?? 2);
const childrenAges = ref(props.tripParams?.children_ages ?? '');
const interests = ref(props.tripParams?.interests ?? '');
const budgetTier = ref(props.tripParams?.budget_tier || 'mid-range');
const startLocation = ref(props.startLocation);
const endLocation = ref(props.endLocation);

const validationError = ref('');

function submit() {
    if (!dateFrom.value || !dateTo.value) {
        validationError.value = t('itinerary.paramsEditor.datesRequired');

        return;
    }

    if (new Date(dateTo.value) <= new Date(dateFrom.value)) {
        validationError.value = t('itinerary.paramsEditor.dateOrderInvalid');

        return;
    }

    if (!startLocation.value.trim() || !endLocation.value.trim()) {
        validationError.value = t('itinerary.paramsEditor.locationsRequired');

        return;
    }

    validationError.value = '';

    emit('save', {
        dateFrom: dateFrom.value,
        dateTo: dateTo.value,
        adults: adults.value,
        childrenAges: childrenAges.value,
        interests: interests.value,
        budgetTier: budgetTier.value,
        startLocation: startLocation.value.trim(),
        endLocation: endLocation.value.trim(),
    });
}
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <div class="modal-box params-modal-box" role="dialog" aria-modal="true">
            <button
                class="modal-close"
                :aria-label="t('saveModal.close')"
                @click="emit('close')"
            >
                ×
            </button>

            <h3>{{ t('itinerary.paramsEditor.title') }}</h3>
            <p class="modal-sub">{{ t('itinerary.paramsEditor.subtitle') }}</p>

            <form class="params-form" @submit.prevent="submit">
                <div class="params-row">
                    <label class="params-field">
                        <span>{{ t('itinerary.paramsEditor.dateFrom') }}</span>
                        <input v-model="dateFrom" type="date" required />
                    </label>
                    <label class="params-field">
                        <span>{{ t('itinerary.paramsEditor.dateTo') }}</span>
                        <input v-model="dateTo" type="date" required />
                    </label>
                </div>

                <div class="params-row">
                    <label class="params-field">
                        <span>{{ t('itinerary.paramsEditor.adults') }}</span>
                        <input
                            v-model.number="adults"
                            type="number"
                            min="1"
                            max="20"
                            required
                        />
                    </label>
                    <label class="params-field">
                        <span>{{
                            t('itinerary.paramsEditor.childrenAges')
                        }}</span>
                        <input
                            v-model="childrenAges"
                            type="text"
                            :placeholder="
                                t(
                                    'itinerary.paramsEditor.childrenAgesPlaceholder',
                                )
                            "
                        />
                    </label>
                </div>

                <label class="params-field">
                    <span>{{ t('itinerary.paramsEditor.interests') }}</span>
                    <textarea
                        v-model="interests"
                        rows="2"
                        :placeholder="
                            t('itinerary.paramsEditor.interestsPlaceholder')
                        "
                    />
                </label>

                <label class="params-field">
                    <span>{{ t('itinerary.paramsEditor.budget') }}</span>
                    <select v-model="budgetTier">
                        <option value="budget">
                            {{ t('itinerary.meta.budgetTiers.budget') }}
                        </option>
                        <option value="mid-range">
                            {{ t('itinerary.meta.budgetTiers.mid-range') }}
                        </option>
                        <option value="premium">
                            {{ t('itinerary.meta.budgetTiers.premium') }}
                        </option>
                    </select>
                </label>

                <div class="params-row">
                    <label class="params-field">
                        <span>{{
                            t('itinerary.paramsEditor.startLocation')
                        }}</span>
                        <input
                            v-model="startLocation"
                            type="text"
                            list="trip-params-location-suggestions"
                            required
                        />
                    </label>
                    <label class="params-field">
                        <span>{{
                            t('itinerary.paramsEditor.endLocation')
                        }}</span>
                        <input
                            v-model="endLocation"
                            type="text"
                            list="trip-params-location-suggestions"
                            required
                        />
                    </label>
                </div>
                <datalist id="trip-params-location-suggestions">
                    <option
                        v-for="s in locationSuggestions"
                        :key="s"
                        :value="s"
                    />
                </datalist>

                <p v-if="validationError || error" class="form-error">
                    {{ validationError || error }}
                </p>

                <div class="params-actions">
                    <button
                        type="button"
                        class="params-cancel"
                        :disabled="saving"
                        @click="emit('close')"
                    >
                        {{ t('itinerary.paramsEditor.cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="modal-submit"
                        :disabled="saving"
                    >
                        {{
                            saving
                                ? t('itinerary.paramsEditor.saving')
                                : t('itinerary.paramsEditor.save')
                        }}
                    </button>
                </div>
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
    overflow-y: auto;
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
.modal-close:hover {
    color: var(--ink, #241c15);
}
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
.modal-submit:hover:not(:disabled) {
    background: var(--night-deep, #101a30);
}
.modal-submit:disabled {
    opacity: 0.5;
    cursor: default;
}
.form-error {
    font-size: 13px;
    color: #b02a2a;
    margin: 0;
}
.params-modal-box {
    max-width: 480px;
    text-align: left;
}
.params-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.params-row {
    display: flex;
    gap: 12px;
}
.params-row .params-field {
    flex: 1;
    min-width: 0;
}
.params-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
    font-size: 12.5px;
    font-weight: 600;
    color: #5b5346;
}
.params-field input,
.params-field select,
.params-field textarea {
    font-family: 'Inter', sans-serif;
    font-weight: 400;
    padding: 9px 11px;
    border: 1px solid var(--sand-dark, #ded2b8);
    border-radius: 8px;
    background: var(--paper, #fbf8f1);
    color: var(--ink, #241c15);
    font-size: 14px;
}
.params-field input:focus,
.params-field select:focus,
.params-field textarea:focus {
    outline: 2px solid var(--rust, #b5651d);
    outline-offset: 1px;
}
.params-field textarea {
    resize: vertical;
    font-family: 'Inter', sans-serif;
}
.params-actions {
    display: flex;
    gap: 10px;
    margin-top: 4px;
}
.params-actions .modal-submit {
    flex: 1;
    margin-top: 0;
}
.params-cancel {
    flex: 1;
    padding: 11px;
    background: transparent;
    border: 1px solid var(--sand-dark, #ded2b8);
    border-radius: 9px;
    font-size: 14px;
    font-weight: 600;
    font-family: 'Inter', sans-serif;
    color: #5b5346;
    cursor: pointer;
}
.params-cancel:hover:not(:disabled) {
    background: var(--sand, #ede6d6);
}
.params-cancel:disabled {
    opacity: 0.5;
    cursor: default;
}
</style>
