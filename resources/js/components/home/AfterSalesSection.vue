<script setup lang="ts">
import { ref } from 'vue';

const supportText = ref('');
const supportNote = ref('');
function sendSupport() {
    if (!supportText.value.trim()) {
return;
}

    supportNote.value = 'Support notified. Someone will reach out shortly.';
}

const feedbackText = ref('');
const feedbackNote = ref('');
const rating = ref(0);
function publishFeedback() {
    feedbackNote.value = 'Thanks — published to the community.';
}
</script>

<template>
    <section>
        <div class="section-head">
            <div class="eyebrow">After your trip</div>
            <h2>The companion doesn't stop at check-in</h2>
            <p>Preparation, support on the road, and a feedback loop that improves every future traveler's plan.</p>
        </div>
        <div class="aftersales-grid">
            <div class="as-card">
                <h3>Prep checklist</h3>
                <p>Route-specific, generated with the plan.</p>
                <div class="checklist">
                    <label><input type="checkbox" />Confirm 4x4 rental &amp; spare tyre</label>
                    <label><input type="checkbox" />Cash for park entry fees</label>
                    <label><input type="checkbox" />Download offline maps for Damaraland</label>
                    <label><input type="checkbox" />Fuel up before Sesriem — next station is 2h out</label>
                </div>
                <button class="small" @click="window.print()">Print checklist</button>
            </div>
            <div class="as-card">
                <h3>Something went wrong on the road</h3>
                <p>Flat tyre, closed route, a booking that fell through — get help without hunting for a phone number.</p>
                <textarea v-model="supportText" placeholder="Describe what happened..."></textarea>
                <button class="small" @click="sendSupport">Send</button>
                <div v-if="supportNote" class="confirm-note">{{ supportNote }}</div>
            </div>
            <div class="as-card">
                <h3>Share your trip</h3>
                <p>Feeds better suggestions to the next traveler planning a similar route.</p>
                <div class="stars">
                    <span
                        v-for="n in 5"
                        :key="n"
                        :class="{ active: n <= rating }"
                        @click="rating = n"
                    >★</span>
                </div>
                <textarea v-model="feedbackText" placeholder="What was the highlight?"></textarea>
                <button class="small" @click="publishFeedback">Publish</button>
                <div v-if="feedbackNote" class="confirm-note">{{ feedbackNote }}</div>
            </div>
        </div>
    </section>
</template>
