import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

export const SYMBOLS: Record<string, string> = {
    NAD: 'N$',
    USD: '$',
    EUR: '€',
    ZAR: 'R',
    GBP: '£',
};

// All prices from the backend arrive in NAD — this only affects display.
export const currentCurrency = ref('NAD');
const rates = ref<Record<string, number>>({ NAD: 1 });

function applyCurrency(
    currency: string | undefined,
    currencyRates: Record<string, number> | undefined,
): void {
    if (currency) {
        currentCurrency.value = currency;
    }

    if (currencyRates) {
        rates.value = currencyRates;
    }
}

export function initializeCurrency(
    initialCurrency: string | undefined,
    initialRates: Record<string, number> | undefined,
): void {
    applyCurrency(initialCurrency, initialRates);

    router.on('success', (event) => {
        const props = (event as CustomEvent).detail?.page?.props;
        applyCurrency(
            props?.currency as string | undefined,
            props?.currencyRates as Record<string, number> | undefined,
        );
    });
}

/**
 * Converts a NAD amount to the traveler's selected display currency and
 * formats it with the right symbol — e.g. formatPrice("1200") -> "$ 66".
 * Returns null for missing/empty amounts so callers can `v-if` on it.
 */
/**
 * The inverse of formatPrice's conversion: what the traveler typed in their
 * display currency, as NAD — which is what every price in this system is
 * stored and compared in, so anything the traveler enters has to cross back
 * over before it is sent.
 *
 * Rounded to whole NAD on purpose: these are round numbers a person picked
 * ("about 1200 a day"), and carrying cents through an exchange rate would
 * dress a guess up as a measurement.
 */
export function toNad(amountInDisplayCurrency: number): number {
    const rate = rates.value[currentCurrency.value] || 1;

    return Math.round(amountInDisplayCurrency / rate);
}

/** The same trip the other way, for pre-filling an input from a stored NAD amount. */
export function fromNad(amountNad: number): number {
    const rate = rates.value[currentCurrency.value] || 1;

    return Math.round(amountNad * rate);
}

export function formatPrice(
    amountNad: string | number | null | undefined,
): string | null {
    if (amountNad === null || amountNad === undefined || amountNad === '') {
        return null;
    }

    const amount = Number(amountNad);

    if (Number.isNaN(amount)) {
        return null;
    }

    const rate = rates.value[currentCurrency.value] ?? 1;
    const symbol = SYMBOLS[currentCurrency.value] ?? currentCurrency.value;
    const converted = Math.round(amount * rate);

    return `${symbol} ${converted.toLocaleString()}`;
}
