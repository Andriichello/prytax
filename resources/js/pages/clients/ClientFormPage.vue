<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, Client } from '@/api/types';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { locale, t } from '@/i18n';
import { ABBREVIATION_MAX, COMMON_COUNTRIES, countryName, suggestAbbreviation } from '@/utils/clients';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Клієнт — новий або редагування: the name on invoices and the abbreviation, which prefixes its invoice numbers
 * (suggested from the name until it is typed), the legal name, country and currency, the address (up to four lines)
 * and tax numbers printed on invoices, the e-mail for invoices, a note printed under them and an inner note.
 */
const MAX_LINES = 4;
const CURRENCIES = ['USD', 'EUR', 'GBP', 'PLN', 'CHF', 'CAD', 'UAH'];

const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path } = useCurrentProfile();

const clientId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => clientId.value === null);

const form = reactive({
    name: '',
    abbreviation: '',
    legal_name: '',
    country: 'US',
    currency: 'USD',
    lines: ['', ''],
    city: '',
    state: '',
    zip: '',
    tax_id: '',
    vat_number: '',
    email: '',
    invoice_footer_note: '',
    notes: '',
});

/** Whether the abbreviation was typed (or is the saved one), so the name no longer suggests it. */
const abbreviationTyped = ref(false);

const { data: existing, error: loadError, loading, reload } = useLoader(async (signal) => {
    if (clientId.value === null) {
        return null;
    }

    const { data } = await api.get<ApiResponse<Client>>(path(`clients/${clientId.value}`), undefined, signal);

    abbreviationTyped.value = true;
    Object.assign(form, {
        name: data.name,
        abbreviation: data.abbreviation,
        legal_name: data.legal_name ?? '',
        country: data.country ?? '',
        currency: data.currency ?? 'USD',
        lines: [...data.address?.lines ?? [], '', ''].slice(0, Math.max(2, data.address?.lines?.length ?? 0)).map((line) => line ?? ''),
        city: data.address?.city ?? '',
        state: data.address?.state ?? '',
        zip: data.address?.zip ?? '',
        tax_id: data.tax_id ?? '',
        vat_number: data.vat_number ?? '',
        email: data.email ?? '',
        invoice_footer_note: data.invoice_footer_note ?? '',
        notes: data.notes ?? '',
    });

    return data;
}, [profileId, clientId]);

watch(() => form.name, (name) => {
    if (!abbreviationTyped.value) {
        form.abbreviation = suggestAbbreviation(name);
    }
});

/** The abbreviation as it is typed, in upper case; an emptied one is suggested from the name again. */
const abbreviation = computed({
    get: () => form.abbreviation,
    set: (value: string) => {
        form.abbreviation = value.toUpperCase();
        abbreviationTyped.value = value !== '';
    },
});

const abbreviationError = computed(() => (/^[A-Z0-9]*$/.test(form.abbreviation.trim())
    ? fieldError(saveError.value, 'abbreviation')
    : t('clients.form.latinLettersDigits')));

const countries = computed(() => {
    const codes = COMMON_COUNTRIES.includes(form.country) || !form.country ? COMMON_COUNTRIES : [form.country, ...COMMON_COUNTRIES];

    return codes
        .map((code) => ({ value: code, label: `${countryName(code)} · ${code}` }))
        .sort((a, b) => a.label.localeCompare(b.label, locale.value));
});

const saving = ref(false);
const saveError = ref<unknown>(null);

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    const lines = form.lines.map((line) => line.trim()).filter(Boolean);
    const address = { lines, city: form.city.trim() || null, state: form.state.trim() || null, zip: form.zip.trim() || null };
    const body = {
        name: form.name.trim(),
        abbreviation: form.abbreviation.trim(),
        legal_name: form.legal_name.trim() || null,
        country: form.country || null,
        currency: form.currency || null,
        address: lines.length || address.city || address.state || address.zip ? address : null,
        tax_id: form.tax_id.trim() || null,
        vat_number: form.vat_number.trim() || null,
        email: form.email.trim() || null,
        invoice_footer_note: form.invoice_footer_note.trim() || null,
        notes: form.notes.trim() || null,
    };

    try {
        const { data } = isNew.value
            ? await api.post<ApiResponse<Client>>(path('clients'), body)
            : await api.patch<ApiResponse<Client>>(path(`clients/${clientId.value}`), body);

        await router.replace({ name: 'client', params: { id: data.id } });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const generalError = computed(() => {
    const fields = ['name', 'abbreviation', 'legal_name', 'country', 'currency', 'email', 'tax_id', 'vat_number'];

    return saveError.value && !fields.some((field) => fieldError(saveError.value, field)) ? errorMessage(saveError.value) : null;
});
</script>

<template>
    <SubScreen :title="isNew ? t('clients.form.newClient') : existing?.name ?? t('core.common.client')" :back="isNew ? { name: 'clients' } : { name: 'client', params: { id: clientId } }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <TextField v-model="form.name" :label="t('core.common.name')" placeholder="Brightline Labs LLC" :hint="t('clients.form.nameHint')" :error="fieldError(saveError, 'name')" />
            <TextField
                v-model="abbreviation"
                :label="t('clients.form.abbreviation')"
                placeholder="BL"
                class="num"
                autocapitalize="characters"
                autocomplete="off"
                spellcheck="false"
                :maxlength="ABBREVIATION_MAX"
                :hint="t('clients.form.abbreviationHint', { abbreviation: form.abbreviation.trim() || 'BL' })"
                :error="abbreviationError"
            />
            <TextField v-model="form.legal_name" :label="t('clients.form.legalName')" placeholder="Brightline Labs, LLC" :error="fieldError(saveError, 'legal_name')" />
            <div class="grid grid-cols-2 gap-2.5">
                <SelectField v-model="form.country" :label="t('clients.form.country')" :options="countries" :error="fieldError(saveError, 'country')" />
                <SelectField
                    v-model="form.currency"
                    :label="t('core.common.currency')"
                    :options="CURRENCIES.map((currency) => ({ value: currency, label: currency }))"
                    :error="fieldError(saveError, 'currency')"
                />
            </div>

            <section class="flex flex-col gap-3">
                <SectionTitle>{{ t('clients.form.invoiceAddress') }}</SectionTitle>
                <TextField
                    v-for="(_, index) in form.lines"
                    :key="index"
                    v-model="form.lines[index]"
                    :label="index === 0 ? t('core.common.address') : t('clients.form.addressLine', { line: index + 1 })"
                    :placeholder="index === 0 ? '500 Market St, Suite 200' : t('core.common.optional')"
                />
                <button
                    v-if="form.lines.length < MAX_LINES"
                    type="button"
                    class="-mt-1 flex min-h-11 items-center self-start text-15 font-medium text-accent"
                    @click="form.lines.push('')"
                >
                    {{ t('clients.form.addAddressLine') }}
                </button>
                <div class="grid grid-cols-2 gap-2.5">
                    <TextField v-model="form.city" :label="t('clients.form.city')" />
                    <TextField v-model="form.state" :label="t('clients.form.stateRegion')" :placeholder="t('core.common.optional')" />
                </div>
                <TextField v-model="form.zip" :label="t('clients.form.postalCode')" class="num" />
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>{{ t('clients.form.taxDetails') }}</SectionTitle>
                <div class="grid grid-cols-2 gap-2.5">
                    <TextField v-model="form.tax_id" :label="t('clients.detail.taxNumber')" class="num" placeholder="EIN" :error="fieldError(saveError, 'tax_id')" />
                    <TextField v-model="form.vat_number" label="VAT" class="num" :placeholder="t('core.common.optional')" :error="fieldError(saveError, 'vat_number')" />
                </div>
                <TextField v-model="form.email" :label="t('clients.detail.invoiceEmail')" type="email" :error="fieldError(saveError, 'email')" />
            </section>

            <TextAreaField v-model="form.invoice_footer_note" :label="t('clients.detail.invoiceNote')" :rows="2" :placeholder="t('clients.form.invoiceNoteHint')" />
            <TextAreaField v-model="form.notes" :label="t('invoices.editor.internalNote')" :rows="2" :placeholder="t('clients.form.notPrinted')" />

            <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </LoadState>

        <template v-if="can(isNew ? 'create' : 'update', 'clients')" #footer>
            <PrimaryButton :busy="saving" :disabled="loading || !form.name.trim() || !/^[A-Z0-9]+$/.test(form.abbreviation.trim())" @click="save">{{ t('core.common.save') }}</PrimaryButton>
        </template>
    </SubScreen>
</template>
