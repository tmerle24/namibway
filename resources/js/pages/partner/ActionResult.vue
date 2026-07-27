<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
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
    outcome: 'confirmed' | 'cancelled' | 'already_handled';
    message: string;
    inquiry?: Inquiry;
}>();

const icons = {
    confirmed: '✓',
    cancelled: '✕',
    already_handled: '·',
};

const colors = {
    confirmed: '#22c55e',
    cancelled: '#ef4444',
    already_handled: '#6b7280',
};
</script>

<template>
    <Head title="NamibWay — Booking action" />

    <div class="partner-page">
        <header class="partner-header">
            <img :src="logoDark" alt="NamibWay" class="logo" />
        </header>

        <main class="partner-main">
            <div class="result-card">
                <div class="result-icon" :style="{ color: colors[outcome] }">
                    {{ icons[outcome] }}
                </div>

                <p class="result-message">{{ message }}</p>

                <div v-if="inquiry" class="inquiry-details">
                    <div class="detail-row">
                        <span class="label">Guest</span>
                        <span>{{ inquiry.guest_name }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Property</span>
                        <span>{{ inquiry.listing_name }}</span>
                    </div>
                    <template v-if="inquiry.check_in">
                        <div class="detail-row">
                            <span class="label">Check-in</span>
                            <span>{{ inquiry.check_in }}</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Check-out</span>
                            <span>{{ inquiry.check_out }}</span>
                        </div>
                    </template>
                    <div v-if="inquiry.connector_reference" class="detail-row">
                        <span class="label">Reference</span>
                        <span>{{ inquiry.connector_reference }}</span>
                    </div>
                </div>
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

.result-card {
    background: white;
    border-radius: 1rem;
    padding: 3rem 2.5rem;
    max-width: 480px;
    width: 100%;
    text-align: center;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
}

.result-icon {
    font-size: 3.5rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 1.25rem;
}

.result-message {
    font-size: 1.125rem;
    color: #374151;
    margin-bottom: 2rem;
}

.inquiry-details {
    border-top: 1px solid #e5e7eb;
    padding-top: 1.5rem;
    text-align: left;
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    font-size: 0.875rem;
    gap: 1rem;
}

.label {
    color: #9ca3af;
    flex-shrink: 0;
}
</style>
