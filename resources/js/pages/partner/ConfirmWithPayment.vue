<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import logoDark from '../../../images/logo-dark.png';

interface Inquiry {
    id: number;
    guest_name: string;
    listing_name: string;
    check_in?: string;
    check_out?: string;
    connector_reference?: string;
}

const props = defineProps<{
    /** The signed address this page was reached at — the form posts back to it. */
    action: string;
    handled: boolean;
    inquiry: Inquiry;
}>();

const form = useForm({ message: '' });

const submit = () => form.post(props.action, { preserveScroll: true });
</script>

<template>
    <Head title="NamibWay — Confirm and ask for payment" />

    <div class="partner-page">
        <header class="partner-header">
            <img :src="logoDark" alt="NamibWay" class="logo" />
        </header>

        <main class="partner-main">
            <div class="card">
                <template v-if="handled">
                    <h1>Already handled</h1>
                    <p class="lead">
                        This booking request has already been answered.
                    </p>
                </template>

                <template v-else>
                    <h1>Confirm and ask for the deposit</h1>
                    <p class="lead">
                        {{ inquiry.guest_name }} gets one email: the
                        confirmation, your message, and a button to pay the
                        deposit.
                    </p>

                    <div class="details">
                        <div class="row">
                            <span class="label">Guest</span
                            ><span>{{ inquiry.guest_name }}</span>
                        </div>
                        <div class="row">
                            <span class="label">Property</span
                            ><span>{{ inquiry.listing_name }}</span>
                        </div>
                        <template v-if="inquiry.check_in">
                            <div class="row">
                                <span class="label">Check-in</span
                                ><span>{{ inquiry.check_in }}</span>
                            </div>
                            <div class="row">
                                <span class="label">Check-out</span
                                ><span>{{ inquiry.check_out }}</span>
                            </div>
                        </template>
                    </div>

                    <form @submit.prevent="submit">
                        <label for="message"
                            >A message for the guest
                            <span class="optional">(optional)</span></label
                        >
                        <textarea
                            id="message"
                            v-model="form.message"
                            rows="5"
                            maxlength="2000"
                            placeholder="Anything you would like to say — directions, what to bring, when you are expecting them."
                        ></textarea>
                        <p v-if="form.errors.message" class="error">
                            {{ form.errors.message }}
                        </p>

                        <button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? 'Sending…'
                                    : 'Confirm and send the payment link'
                            }}
                        </button>
                    </form>
                </template>
            </div>
        </main>
    </div>
</template>

<style scoped>
.partner-page {
    min-height: 100vh;
    background: #f8f7f4;
    display: flex;
    flex-direction: column;
}

.partner-header {
    padding: 1.5rem 2rem;
    background: #1a1a1a;
    display: flex;
    justify-content: center;
}

.logo {
    height: 2rem;
}

.partner-main {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
}

.card {
    background: white;
    border-radius: 1rem;
    padding: 2.5rem;
    max-width: 480px;
    width: 100%;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
}

h1 {
    font-size: 1.375rem;
    font-weight: 700;
    color: #1a1a1a;
    margin-bottom: 0.5rem;
}

.lead {
    color: #4b5563;
    font-size: 0.9375rem;
    margin-bottom: 1.5rem;
}

.details {
    border-top: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
    padding: 1.25rem 0;
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    margin-bottom: 1.5rem;
}

.row {
    display: flex;
    justify-content: space-between;
    font-size: 0.875rem;
    gap: 1rem;
}

.label {
    color: #9ca3af;
    flex-shrink: 0;
}

label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
}

.optional {
    font-weight: 400;
    color: #9ca3af;
}

textarea {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    padding: 0.75rem;
    font: inherit;
    font-size: 0.9375rem;
    color: #1a1a1a;
    resize: vertical;
}

textarea:focus {
    outline: 2px solid #9c4a21;
    outline-offset: 1px;
    border-color: transparent;
}

.error {
    color: #b91c1c;
    font-size: 0.8125rem;
    margin-top: 0.375rem;
}

button {
    width: 100%;
    margin-top: 1.25rem;
    padding: 0.875rem 1rem;
    border: 0;
    border-radius: 0.5rem;
    background: #22c55e;
    color: white;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
}

button:disabled {
    opacity: 0.6;
    cursor: default;
}
</style>
