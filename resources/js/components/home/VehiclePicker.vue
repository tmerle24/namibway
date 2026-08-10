<script setup lang="ts">
import { Bus, Car, Caravan, Coins, Tent, Truck } from '@lucide/vue';
import type { FunctionalComponent } from 'vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { SYMBOLS, currentCurrency, fromNad, toNad } from '@/lib/currency';
import type { VehicleClass } from '@/lib/kaia-types';
import { VEHICLE_CLASSES } from '@/lib/kaia-types';

const props = defineProps<{
    vehicleClass: VehicleClass | null;
    /** NAD per day, or null for "no ceiling given". */
    dailyBudget: number | null;
}>();

const emit = defineEmits<{
    (e: 'update:vehicleClass', value: VehicleClass): void;
    (e: 'update:dailyBudget', value: number | null): void;
}>();

const { t } = useI18n();

// One glance per option — the picker is meant to be scanned, not read. The
// icons carry the distinction the labels spell out (a tent on the roof vs. a
// van you sleep inside), so the row still parses at phone width where the
// hints wrap to two lines.
const ICONS: Record<VehicleClass, FunctionalComponent> = {
    sedan: Car,
    suv: Truck,
    camper_4x4: Tent,
    motorhome: Caravan,
    minibus: Bus,
};

const symbol = computed(
    () => SYMBOLS[currentCurrency.value] ?? currentCurrency.value,
);

// Held as the raw string rather than a number so an emptied field stays empty
// instead of snapping to 0 — and converted to NAD only on the way out, since
// the traveler types in whatever currency they are browsing in.
const budgetInput = ref(
    props.dailyBudget !== null && props.dailyBudget > 0
        ? String(fromNad(props.dailyBudget))
        : '',
);

function onBudgetInput(value: string) {
    budgetInput.value = value;

    const parsed = Number(value);
    const isBlank = value.trim() === '';

    emit(
        'update:dailyBudget',
        isBlank || Number.isNaN(parsed) || parsed <= 0 ? null : toNad(parsed),
    );
}
</script>

<template>
    <div class="vehicle-picker">
        <span class="vehicle-picker-label">{{
            t('itinerary.vehiclePicker.label')
        }}</span>

        <div
            class="vehicle-options"
            role="group"
            :aria-label="t('itinerary.vehiclePicker.label')"
        >
            <button
                v-for="option in VEHICLE_CLASSES"
                :key="option"
                type="button"
                class="vehicle-option"
                :class="{ 'vehicle-option--selected': vehicleClass === option }"
                :aria-pressed="vehicleClass === option"
                @click="emit('update:vehicleClass', option)"
            >
                <component
                    :is="ICONS[option]"
                    :size="20"
                    class="vehicle-option-icon"
                    aria-hidden="true"
                />
                <span class="vehicle-option-name">{{
                    t(`itinerary.vehiclePicker.classes.${option}.name`)
                }}</span>
                <span class="vehicle-option-hint">{{
                    t(`itinerary.vehiclePicker.classes.${option}.hint`)
                }}</span>
            </button>
        </div>

        <label class="vehicle-budget">
            <span class="vehicle-picker-label">
                {{ t('itinerary.vehiclePicker.budget') }}
                <span class="vehicle-budget-optional">{{
                    t('itinerary.vehiclePicker.optional')
                }}</span>
            </span>
            <span class="vehicle-budget-field">
                <Coins
                    :size="15"
                    class="vehicle-budget-icon"
                    aria-hidden="true"
                />
                <span class="vehicle-budget-symbol">{{ symbol }}</span>
                <input
                    :value="budgetInput"
                    type="number"
                    inputmode="numeric"
                    min="0"
                    step="50"
                    :placeholder="
                        t('itinerary.vehiclePicker.budgetPlaceholder')
                    "
                    @input="
                        onBudgetInput(($event.target as HTMLInputElement).value)
                    "
                />
                <span class="vehicle-budget-per-day">{{
                    t('itinerary.vehiclePicker.perDay')
                }}</span>
            </span>
        </label>
    </div>
</template>

<style scoped>
.vehicle-picker {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.vehicle-picker-label {
    font-size: 12.5px;
    font-weight: 600;
    color: #5b5346;
}
/* auto-fit rather than a fixed count: three across on a phone, all five in one
   row on a wide modal, without a breakpoint to keep in sync. */
.vehicle-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(92px, 1fr));
    gap: 8px;
    margin-top: -4px;
}
.vehicle-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    /* Comfortably past the 44px minimum tap target even before the hint wraps. */
    padding: 10px 6px;
    background: var(--paper, #fbf8f1);
    border: 1px solid var(--sand-dark, #ded2b8);
    border-radius: 10px;
    cursor: pointer;
    text-align: center;
    color: #5b5346;
    font-family: 'Inter', sans-serif;
    transition:
        border-color 0.15s,
        background 0.15s,
        color 0.15s;
}
.vehicle-option:hover {
    border-color: var(--rust, #b5651d);
}
.vehicle-option:focus-visible {
    outline: 2px solid var(--rust, #b5651d);
    outline-offset: 1px;
}
.vehicle-option--selected {
    border-color: var(--rust, #b5651d);
    background: rgba(181, 101, 29, 0.09);
    color: var(--ink, #241c15);
}
.vehicle-option-icon {
    color: var(--rust, #b5651d);
}
.vehicle-option-name {
    font-size: 12.5px;
    font-weight: 600;
    line-height: 1.2;
}
.vehicle-option-hint {
    font-size: 10.5px;
    line-height: 1.25;
    opacity: 0.75;
}
.vehicle-budget {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.vehicle-budget-optional {
    font-weight: 400;
    opacity: 0.7;
}
.vehicle-budget-field {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 0 11px;
    border: 1px solid var(--sand-dark, #ded2b8);
    border-radius: 8px;
    background: var(--paper, #fbf8f1);
}
.vehicle-budget-field:focus-within {
    outline: 2px solid var(--rust, #b5651d);
    outline-offset: 1px;
}
.vehicle-budget-icon {
    color: var(--rust, #b5651d);
    flex: none;
}
.vehicle-budget-symbol {
    font-size: 14px;
    color: #5b5346;
    flex: none;
}
.vehicle-budget-field input {
    flex: 1;
    min-width: 0;
    padding: 9px 0;
    border: none;
    background: transparent;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 400;
    color: var(--ink, #241c15);
}
.vehicle-budget-field input:focus {
    outline: none;
}
.vehicle-budget-per-day {
    font-size: 12px;
    color: #5b5346;
    opacity: 0.8;
    flex: none;
}
</style>
