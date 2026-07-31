<script setup lang="ts">
import '../../css/kaia-home.css';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Eye, Send, X } from '@lucide/vue';
import { reactive, ref } from 'vue';
import AdminBar from '@/components/AdminBar.vue';
import logoDark from '../../images/logo-dark.png';

interface Listing {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    short_description: string | null;
    highlights: string[];
    phone: string | null;
    contact_email: string | null;
    website: string | null;
    address: string | null;
    price_from: string | null;
    price_currency: string;
    is_published: boolean;
}

const props = defineProps<{
    listing: Listing;
    preview_token?: string | null;
}>();

const form = reactive({
    name: props.listing.name,
    description: props.listing.description ?? '',
    short_description: props.listing.short_description ?? '',
    highlights: [...props.listing.highlights],
    phone: props.listing.phone ?? '',
    contact_email: props.listing.contact_email ?? '',
    website: props.listing.website ?? '',
    address: props.listing.address ?? '',
    price_from: props.listing.price_from ?? '',
    price_currency: props.listing.price_currency ?? 'NAD',
});

const newHighlight = ref('');
const saving = ref<'draft' | 'preview' | 'publish' | null>(null);
const previewUrl = `/listings/${props.listing.slug}${props.preview_token ? `?preview=${props.preview_token}` : ''}`;

function addHighlight() {
    const value = newHighlight.value.trim();

    if (value && !form.highlights.includes(value)) {
        form.highlights.push(value);
    }

    newHighlight.value = '';
}

function removeHighlight(index: number) {
    form.highlights.splice(index, 1);
}

function submit(mode: 'draft' | 'preview' | 'publish') {
    saving.value = mode;

    router.put(
        `/listings/${props.listing.slug}`,
        {
            ...form,
            preview: props.preview_token ?? undefined,
            publish: mode === 'publish' ? true : undefined,
            redirect: mode !== 'draft' ? 'preview' : undefined,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = null;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Edit ${props.listing.name}`" />

    <div class="kaia-page">
        <AdminBar :edit-url="`/admin/listings/${props.listing.id}/edit`" />

        <div class="detail-topbar">
            <Link :href="previewUrl" class="brand"
                ><img :src="logoDark" alt="NamibWay" class="brand-logo"
            /></Link>
            <Link
                :href="previewUrl"
                class="detail-back"
                style="display: inline-flex; align-items: center; gap: 5px"
            >
                <ArrowLeft :size="14" />
                Back to preview
            </Link>
        </div>

        <div class="edit-header">
            <h1>Edit {{ props.listing.name }}</h1>
            <p>
                {{
                    props.listing.is_published
                        ? 'This listing is live — changes here save immediately, no separate publish step needed.'
                        : "This listing isn't published yet. Save a draft any time, or publish when you're ready."
                }}
            </p>
        </div>

        <form class="inquiry-form edit-form" @submit.prevent>
            <label>
                Name
                <input
                    v-model="form.name"
                    type="text"
                    required
                    maxlength="255"
                />
            </label>

            <label>
                Short description
                <textarea
                    v-model="form.short_description"
                    rows="2"
                    maxlength="500"
                    placeholder="One or two sentences for listing cards"
                ></textarea>
            </label>

            <label>
                Description
                <textarea
                    v-model="form.description"
                    rows="6"
                    maxlength="5000"
                    placeholder="Tell travellers what makes this place worth visiting"
                ></textarea>
            </label>

            <div class="edit-highlights">
                <span class="edit-highlights-label">Highlights</span>
                <div class="edit-highlights-chips">
                    <span
                        v-for="(highlight, i) in form.highlights"
                        :key="highlight"
                        class="edit-highlight-chip"
                    >
                        {{ highlight }}
                        <button
                            type="button"
                            aria-label="Remove highlight"
                            @click="removeHighlight(i)"
                        >
                            <X :size="12" />
                        </button>
                    </span>
                </div>
                <input
                    v-model="newHighlight"
                    type="text"
                    placeholder="Add a highlight and press enter, e.g. Free WiFi"
                    maxlength="100"
                    @keydown.enter.prevent="addHighlight"
                />
            </div>

            <label>
                Phone
                <input v-model="form.phone" type="text" maxlength="50" />
            </label>

            <label>
                Contact email
                <input
                    v-model="form.contact_email"
                    type="email"
                    maxlength="255"
                />
            </label>

            <label>
                Website
                <input
                    v-model="form.website"
                    type="url"
                    placeholder="https://"
                    maxlength="255"
                />
            </label>

            <label>
                Address
                <input v-model="form.address" type="text" maxlength="500" />
            </label>

            <div class="edit-price-row">
                <label>
                    Price from
                    <input
                        v-model="form.price_from"
                        type="number"
                        min="0"
                        step="0.01"
                    />
                </label>
                <label>
                    Currency
                    <input
                        v-model="form.price_currency"
                        type="text"
                        maxlength="3"
                    />
                </label>
            </div>

            <div class="edit-actions">
                <button
                    type="button"
                    class="edit-action edit-action--draft"
                    :disabled="saving !== null"
                    @click="submit('draft')"
                >
                    {{ saving === 'draft' ? 'Saving…' : 'Save draft' }}
                </button>
                <button
                    type="button"
                    class="edit-action edit-action--preview"
                    :disabled="saving !== null"
                    @click="submit('preview')"
                >
                    <Eye :size="14" />
                    {{ saving === 'preview' ? 'Saving…' : 'Save and preview' }}
                </button>
                <button
                    type="button"
                    class="edit-action edit-action--publish"
                    :disabled="saving !== null"
                    @click="submit('publish')"
                >
                    <Send :size="14" />
                    {{
                        saving === 'publish'
                            ? 'Publishing…'
                            : props.listing.is_published
                              ? 'Save changes'
                              : 'Save and publish'
                    }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.edit-header {
    max-width: 640px;
    margin: 32px auto 0;
    padding: 0 24px;
}

.edit-header h1 {
    font-family: 'Fraunces', serif;
    font-size: 28px;
    margin-bottom: 6px;
}

.edit-header p {
    color: #6b6355;
    font-size: 14px;
}

.edit-form {
    max-width: 640px;
    margin: 24px auto 64px;
    padding: 0 24px;
}

.edit-highlights {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.edit-highlights-label {
    font-size: 12.5px;
    font-weight: 600;
    color: #4a4438;
}

.edit-highlights-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.edit-highlight-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #f3ede0;
    border-radius: 999px;
    padding: 4px 6px 4px 10px;
    font-size: 12.5px;
}

.edit-highlight-chip button {
    display: inline-flex;
    background: none;
    border: none;
    color: #6b6355;
    cursor: pointer;
    padding: 2px;
}

.edit-price-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 14px;
}

.edit-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 8px;
}

.edit-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 10px;
    padding: 12px 16px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    border: 1px solid transparent;
}

.edit-action:disabled {
    opacity: 0.6;
    cursor: default;
}

.edit-action--draft {
    background: #fff;
    border-color: var(--sand-dark, #d8cfb8);
    color: var(--ink, #1a1a1a);
}

.edit-action--preview {
    background: #eef2f6;
    color: #1e3a5f;
}

.edit-action--publish {
    background: var(--rust, #b45309);
    color: #fff;
    margin-left: auto;
}

.edit-action--publish:hover:not(:disabled) {
    background: var(--rust-dark, #92400e);
}
</style>
