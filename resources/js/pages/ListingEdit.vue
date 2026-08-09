<script setup lang="ts">
import '../../css/kaia-home.css';
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Eye, EyeOff, Send, UserPlus, X } from '@lucide/vue';
import { reactive, ref } from 'vue';
import AdminBar from '@/components/AdminBar.vue';
import LocationPicker from '@/components/LocationPicker.vue';
import PublishConsentModal from '@/components/PublishConsentModal.vue';
import { thumbAttrs } from '@/lib/media';
import logoDark from '../../images/logo-dark.png';

interface Listing {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    short_description: string | null;
    highlights: string[];
    contact_person: string | null;
    phone: string | null;
    contact_email: string | null;
    website: string | null;
    address: string | null;
    latitude: number | null;
    longitude: number | null;
    price_from: string | null;
    price_currency: string;
    is_published: boolean;
    image: string | null;
    gallery: string[];
    connector_type: string | null;
    connector_property_code: string | null;
    wetu_id: string | null;
    has_connector_credentials: boolean;
    connector_verified: boolean;
}

interface ConnectorOption {
    value: string;
    label: string;
}

const props = defineProps<{
    listing: Listing;
    connector_options: ConnectorOption[];
    preview_token?: string | null;
    claim_url?: string | null;
}>();

const form = reactive({
    name: props.listing.name,
    description: props.listing.description ?? '',
    short_description: props.listing.short_description ?? '',
    highlights: [...props.listing.highlights],
    contact_person: props.listing.contact_person ?? '',
    phone: props.listing.phone ?? '',
    contact_email: props.listing.contact_email ?? '',
    website: props.listing.website ?? '',
    address: props.listing.address ?? '',
    latitude: props.listing.latitude,
    longitude: props.listing.longitude,
    price_from: props.listing.price_from ?? '',
    price_currency: props.listing.price_currency ?? 'NAD',
    connector_type: props.listing.connector_type ?? '',
    connector_property_code: props.listing.connector_property_code ?? '',
    wetu_id: props.listing.wetu_id ?? '',
    // Never prefilled from props — the backend never sends stored credentials
    // back down (see ListingController::edit()), so these start blank even
    // when a system is already connected. Only used while connectorLocked is
    // false (see below); once verified, the backend ignores them entirely.
    resconnect_api_key: '',
    resconnect_base_url: '',
    nightsbridge_bbid: '',
    nightsbridge_api_key: '',
    nightsbridge_base_url: '',
    hopecloud_api_key: '',
    hopecloud_account_id: '',
    hopecloud_base_url: '',
    wetu_api_key: '',
});

// Mirrors the per-system field visibility in the admin Filament wizard
// (BookingConnectorSchema::propertyFields) — API-key connectors share the
// generic property/unit code, Wetu uses its own id column, and Native/Manual/
// NWR need no per-listing identifier entered here at all.
const PROPERTY_CODE_TYPES = ['resconnect', 'nightsbridge', 'hopecloud'];
const CREDENTIALED_TYPES = ['resconnect', 'nightsbridge', 'hopecloud', 'wetu'];

// Once staff has verified the connector that's actually saved, this editor
// stops letting the credentials be touched at all (the backend ignores them
// too — see ListingController::update()). Before that point — including
// while a first-picked system is still pending review — the setup fields
// stay open so the owner can fix a typo before anyone's looked at it.
const connectorLocked =
    props.listing.connector_type !== null && props.listing.connector_verified;

const newHighlight = ref('');
const saving = ref<'draft' | 'preview' | 'publish' | 'unpublish' | null>(null);
const showPublishModal = ref(false);
const previewUrl = `/listings/${props.listing.slug}${props.preview_token ? `?preview=${props.preview_token}` : ''}`;

// --- Hero image ---
const heroImageFile = ref<File | null>(null);
const heroImagePreview = ref<string | null>(props.listing.image);
const removeHeroImage = ref(false);

function onHeroImageChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    heroImageFile.value = file;
    removeHeroImage.value = false;
    heroImagePreview.value = URL.createObjectURL(file);
}

function clearHeroImage() {
    heroImageFile.value = null;
    heroImagePreview.value = null;
    removeHeroImage.value = true;
}

// --- Gallery ---
interface GalleryItem {
    key: string;
    url: string;
    kind: 'existing' | 'new';
    file?: File;
}

const gallery = reactive<GalleryItem[]>(
    props.listing.gallery.map((url, i) => ({
        key: `existing-${i}`,
        url,
        kind: 'existing',
    })),
);
const galleryTouched = ref(false);

function onGalleryFilesChange(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);

    files.forEach((file) => {
        gallery.push({
            key: `new-${Date.now()}-${Math.random()}`,
            url: URL.createObjectURL(file),
            kind: 'new',
            file,
        });
    });

    galleryTouched.value = true;
    input.value = '';
}

function removeGalleryItem(key: string) {
    const index = gallery.findIndex((item) => item.key === key);

    if (index !== -1) {
        gallery.splice(index, 1);
    }

    galleryTouched.value = true;
}

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

function submit(mode: 'draft' | 'preview' | 'publish' | 'unpublish') {
    saving.value = mode;

    const payload: Record<string, FormDataConvertible> = {
        ...form,
        connector_type: form.connector_type === '' ? null : form.connector_type,
        connector_property_code: PROPERTY_CODE_TYPES.includes(
            form.connector_type,
        )
            ? form.connector_property_code
            : '',
        wetu_id: form.connector_type === 'wetu' ? form.wetu_id : '',
        preview: props.preview_token ?? undefined,
        publish: mode === 'publish' ? true : undefined,
        unpublish: mode === 'unpublish' ? true : undefined,
        terms_accepted: mode === 'publish' ? true : undefined,
        redirect:
            mode === 'preview' || mode === 'publish' ? 'preview' : undefined,
    };

    if (heroImageFile.value) {
        payload.image = heroImageFile.value;
    } else if (removeHeroImage.value) {
        payload.remove_image = true;
    }

    if (galleryTouched.value) {
        payload.gallery_touched = true;
        payload.gallery_keep = gallery
            .filter((item) => item.kind === 'existing')
            .map((item) => item.url);
        payload.gallery_new = gallery
            .filter((item) => item.kind === 'new')
            .map((item) => item.file);
    }

    router.put(`/listings/${props.listing.slug}`, payload, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            saving.value = null;
        },
    });
}

function confirmPublish() {
    showPublishModal.value = false;
    submit('publish');
}

// Only a listing that isn't live yet needs the publish confirmation modal —
// re-saving an already-published listing isn't a new publish event, so it
// doesn't need fresh Terms & Conditions acceptance each time.
function handlePublishClick() {
    if (props.listing.is_published) {
        submit('preview');
    } else {
        showPublishModal.value = true;
    }
}

function handleUnpublishClick() {
    if (
        !window.confirm(
            `Unpublish "${props.listing.name}"? It will no longer be visible to travellers on namibway.com until you publish it again.`,
        )
    ) {
        return;
    }

    submit('unpublish');
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
            <div class="detail-topbar-actions">
                <a
                    v-if="props.claim_url"
                    :href="props.claim_url"
                    class="owner-header-link owner-header-link--claim"
                >
                    <UserPlus :size="14" />
                    Claim account
                </a>
                <Link
                    :href="previewUrl"
                    class="detail-back"
                    style="display: inline-flex; align-items: center; gap: 5px"
                >
                    <ArrowLeft :size="14" />
                    Back to preview
                </Link>
            </div>
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

            <div class="edit-section">
                <span class="edit-section-label">Photos</span>

                <div class="edit-hero-image">
                    <div class="edit-hero-preview">
                        <!-- Also fed by URL.createObjectURL for a just-picked
                             file; a blob: URL isn't ours to resize, so it falls
                             through thumbAttrs untouched. -->
                        <img
                            v-if="heroImagePreview"
                            v-bind="thumbAttrs(heroImagePreview, 160)"
                            alt=""
                            width="160"
                            height="90"
                            decoding="async"
                        />
                        <span v-else class="edit-hero-preview-empty"
                            >No hero image yet</span
                        >
                    </div>
                    <div class="edit-hero-actions">
                        <label class="edit-file-button">
                            {{
                                heroImagePreview ? 'Change photo' : 'Add photo'
                            }}
                            <input
                                type="file"
                                accept="image/*"
                                style="display: none"
                                @change="onHeroImageChange"
                            />
                        </label>
                        <button
                            v-if="heroImagePreview"
                            type="button"
                            class="edit-file-remove"
                            @click="clearHeroImage"
                        >
                            Remove
                        </button>
                    </div>
                </div>

                <div class="edit-gallery">
                    <span class="edit-gallery-label">Gallery</span>
                    <div class="edit-gallery-grid">
                        <div
                            v-for="item in gallery"
                            :key="item.key"
                            class="edit-gallery-item"
                        >
                            <img
                                v-bind="thumbAttrs(item.url, 90)"
                                alt=""
                                loading="lazy"
                                decoding="async"
                            />
                            <button
                                type="button"
                                aria-label="Remove photo"
                                class="edit-gallery-remove"
                                @click="removeGalleryItem(item.key)"
                            >
                                <X :size="12" />
                            </button>
                        </div>
                        <label class="edit-gallery-add">
                            + Add photos
                            <input
                                type="file"
                                accept="image/*"
                                multiple
                                style="display: none"
                                @change="onGalleryFilesChange"
                            />
                        </label>
                    </div>
                </div>
            </div>

            <label>
                Contact person
                <input
                    v-model="form.contact_person"
                    type="text"
                    maxlength="255"
                />
            </label>

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

            <div class="edit-section">
                <span class="edit-section-label">Location</span>
                <LocationPicker
                    map-id="listing-edit-location-map"
                    :latitude="form.latitude"
                    :longitude="form.longitude"
                    @update:latitude="(v) => (form.latitude = v)"
                    @update:longitude="(v) => (form.longitude = v)"
                />
                <div class="edit-location-row">
                    <label>
                        Latitude
                        <input
                            v-model.number="form.latitude"
                            type="number"
                            step="0.000001"
                            min="-90"
                            max="90"
                        />
                    </label>
                    <label>
                        Longitude
                        <input
                            v-model.number="form.longitude"
                            type="number"
                            step="0.000001"
                            min="-180"
                            max="180"
                        />
                    </label>
                </div>
            </div>

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

            <div class="edit-section">
                <span class="edit-section-label">Booking system</span>

                <template v-if="connectorLocked">
                    <p class="edit-section-hint">
                        <strong>{{
                            props.connector_options.find(
                                (o) => o.value === form.connector_type,
                            )?.label
                        }}</strong>
                        — verified, connected for all of your listings.
                    </p>

                    <label
                        v-if="PROPERTY_CODE_TYPES.includes(form.connector_type)"
                    >
                        Property code
                        <input
                            v-model="form.connector_property_code"
                            type="text"
                            maxlength="100"
                            placeholder="Property/unit ID in that system"
                        />
                    </label>
                    <label v-else-if="form.connector_type === 'wetu'">
                        Wetu Property ID
                        <input
                            v-model="form.wetu_id"
                            type="text"
                            maxlength="100"
                            placeholder="Enables automatic content import from Wetu"
                        />
                    </label>

                    <p class="edit-section-hint">
                        To switch to a different booking system, get in touch
                        with us.
                    </p>
                </template>

                <template v-else>
                    <p class="edit-section-hint">
                        Which system do travellers' booking requests go through?
                        This applies to all of your listings, not just this one.
                    </p>
                    <label>
                        System
                        <select v-model="form.connector_type">
                            <option value="">— none selected —</option>
                            <option
                                v-for="option in props.connector_options"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </label>

                    <template v-if="form.connector_type === 'resconnect'">
                        <label>
                            API key
                            <input
                                v-model="form.resconnect_api_key"
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                        <label>
                            Base URL (optional)
                            <input
                                v-model="form.resconnect_base_url"
                                type="text"
                                placeholder="Only fill in if it differs from the default"
                            />
                        </label>
                    </template>

                    <template
                        v-else-if="form.connector_type === 'nightsbridge'"
                    >
                        <label>
                            Booking Bureau ID (bbid)
                            <input
                                v-model="form.nightsbridge_bbid"
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                        <label>
                            API key
                            <input
                                v-model="form.nightsbridge_api_key"
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                        <label>
                            Base URL (optional)
                            <input
                                v-model="form.nightsbridge_base_url"
                                type="text"
                                placeholder="Only fill in if it differs from the default"
                            />
                        </label>
                    </template>

                    <template v-else-if="form.connector_type === 'hopecloud'">
                        <label>
                            API key
                            <input
                                v-model="form.hopecloud_api_key"
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                        <label>
                            Account ID
                            <input
                                v-model="form.hopecloud_account_id"
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                        <label>
                            Base URL (optional)
                            <input
                                v-model="form.hopecloud_base_url"
                                type="text"
                                placeholder="Only fill in if it differs from the default"
                            />
                        </label>
                    </template>

                    <template v-else-if="form.connector_type === 'wetu'">
                        <label>
                            API key
                            <input
                                v-model="form.wetu_api_key"
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                    </template>

                    <label
                        v-if="PROPERTY_CODE_TYPES.includes(form.connector_type)"
                    >
                        Property code
                        <input
                            v-model="form.connector_property_code"
                            type="text"
                            maxlength="100"
                            placeholder="Property/unit ID in that system"
                        />
                    </label>
                    <label v-else-if="form.connector_type === 'wetu'">
                        Wetu Property ID
                        <input
                            v-model="form.wetu_id"
                            type="text"
                            maxlength="100"
                            placeholder="Enables automatic content import from Wetu"
                        />
                    </label>

                    <p
                        v-else-if="form.connector_type === 'native'"
                        class="edit-section-hint"
                    >
                        No API access needed — bookings are checked and held
                        live against NamibWay's own availability. Room types are
                        managed for you in the admin panel.
                    </p>

                    <p
                        v-else-if="form.connector_type === 'nwr'"
                        class="edit-section-hint"
                    >
                        NWR has no system we can connect to. Every request goes
                        to our team as a manual review.
                    </p>

                    <p
                        v-else-if="form.connector_type === 'manual'"
                        class="edit-section-hint"
                    >
                        Requests are sent to you as a plain email notification,
                        without live availability checks.
                    </p>

                    <p
                        v-if="
                            form.connector_type &&
                            CREDENTIALED_TYPES.includes(form.connector_type)
                        "
                        class="edit-section-hint"
                    >
                        We'll check these details before switching live
                        availability on for this system — you'll still receive
                        requests by email in the meantime.
                    </p>
                </template>
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
                    v-if="props.listing.is_published"
                    type="button"
                    class="edit-action edit-action--unpublish"
                    :disabled="saving !== null"
                    @click="handleUnpublishClick"
                >
                    <EyeOff :size="14" />
                    {{ saving === 'unpublish' ? 'Unpublishing…' : 'Unpublish' }}
                </button>
                <button
                    type="button"
                    class="edit-action edit-action--publish"
                    :disabled="saving !== null"
                    @click="handlePublishClick"
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

        <PublishConsentModal
            :show="showPublishModal"
            :listing-name="props.listing.name"
            @confirm="confirmPublish"
            @cancel="showPublishModal = false"
        />
    </div>
</template>

<style scoped>
.owner-header-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}

.owner-header-link--claim {
    background: transparent;
    color: var(--rust, #b45309);
    border: 1px solid var(--rust, #b45309);
}

.owner-header-link--claim:hover {
    background: #fdf6ec;
}

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

.edit-section {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 16px;
    border: 1px solid var(--sand-dark, #d8cfb8);
    border-radius: 12px;
    background: #fbf8f2;
}

.edit-section-label {
    font-size: 12.5px;
    font-weight: 600;
    color: #4a4438;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.edit-section-hint {
    margin: 0;
    font-size: 12.5px;
    color: #6b6355;
    line-height: 1.4;
}

.edit-hero-image {
    display: flex;
    align-items: center;
    gap: 14px;
}

.edit-hero-preview {
    width: 160px;
    height: 90px;
    flex-shrink: 0;
    border-radius: 8px;
    overflow: hidden;
    background: #efe8d9;
    display: flex;
    align-items: center;
    justify-content: center;
}

.edit-hero-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.edit-hero-preview-empty {
    font-size: 11.5px;
    color: #9a8f78;
    text-align: center;
    padding: 0 10px;
}

.edit-hero-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.edit-file-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    border: 1px solid var(--sand-dark, #d8cfb8);
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.edit-file-remove {
    background: none;
    border: none;
    color: #b45309;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
    text-align: left;
}

.edit-gallery {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.edit-gallery-label {
    font-size: 12.5px;
    font-weight: 600;
    color: #4a4438;
}

.edit-gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
    gap: 8px;
}

.edit-gallery-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: 8px;
    overflow: hidden;
    background: #efe8d9;
}

.edit-gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.edit-gallery-remove {
    position: absolute;
    top: 4px;
    right: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 999px;
    background: rgba(26, 26, 26, 0.65);
    color: #fff;
    border: none;
    cursor: pointer;
}

.edit-gallery-add {
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    border: 1px dashed var(--sand-dark, #d8cfb8);
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 600;
    color: #6b6355;
    cursor: pointer;
    padding: 4px;
}

.edit-location-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
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

.edit-action--unpublish {
    background: #fff;
    border-color: #d8b8ae;
    color: #92400e;
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
