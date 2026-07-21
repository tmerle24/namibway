<script setup lang="ts">
import type { ItineraryPlan, ItineraryVariant } from '@/lib/kaia-demo';

defineProps<{
    plan: ItineraryPlan;
}>();

const emit = defineEmits<{
    (e: 'book', variant: ItineraryVariant): void;
}>();
</script>

<template>
    <section id="itinerary-section">
        <div class="section-head">
            <div class="eyebrow">Your plan</div>
            <h2>A few ways to do this trip</h2>
            <p>Generated from the conversation above — accommodation, activities and dining together, not separate silos.</p>
        </div>
        <div class="variants">
            <div v-for="variant in plan.variants" :key="variant.name" class="variant-card">
                <h3>{{ variant.name }}</h3>
                <div class="variant-price">~${{ variant.estimated_total_usd.toLocaleString() }} estimated</div>
                <div v-for="day in variant.days" :key="day.day" class="day-row">
                    <div class="day-num">{{ day.day }}</div>
                    <div class="day-detail">
                        <div><b>{{ day.location }}</b></div>
                        <div>Stay: {{ day.accommodation || '—' }}</div>
                        <div>Activity: {{ day.activity || '—' }}</div>
                        <div>Dinner: {{ day.restaurant || '—' }}</div>
                    </div>
                </div>
                <button class="cta" @click="emit('book', variant)">Request availability &amp; book</button>
            </div>
        </div>
    </section>
</template>
