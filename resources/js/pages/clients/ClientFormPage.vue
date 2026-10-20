<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
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
import { COMMON_COUNTRIES, countryName } from '@/utils/clients';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Клієнт — новий або редагування: the name on invoices, the legal name, country and currency, the address and tax
 * numbers printed on invoices, the e-mail for invoices, a note printed under them and an inner note.
 */
const CURRENCIES = ['USD', 'EUR', 'GBP', 'PLN', 'CHF', 'CAD', 'UAH'];

const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();

const clientId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => clientId.value === null);

const form = reactive({
    name: '',
    legal_name: '',
    country: 'US',
    currency: 'USD',
    line1: '',
    line2: '',
    city: '',
    state: '',
    zip: '',
    tax_id: '',
    vat_number: '',
    email: '',
    invoice_footer_note: '',
    notes: '',
});

const { data: existing, error: loadError, loading, reload } = useLoader(async (signal) => {
    if (clientId.value === null) {
        return null;
    }

    const { data } = await api.get<ApiResponse<Client>>(path(`clients/${clientId.value}`), undefined, signal);

    Object.assign(form, {
        name: data.name,
        legal_name: data.legal_name ?? '',
        country: data.country ?? '',
        currency: data.currency ?? 'USD',
        line1: data.address?.lines?.[0] ?? '',
        line2: data.address?.lines?.[1] ?? '',
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

const countries = computed(() => {
    const codes = COMMON_COUNTRIES.includes(form.country) || !form.country ? COMMON_COUNTRIES : [form.country, ...COMMON_COUNTRIES];

    return codes
        .map((code) => ({ value: code, label: `${countryName(code)} · ${code}` }))
        .sort((a, b) => a.label.localeCompare(b.label, 'uk'));
});

const saving = ref(false);
const saveError = ref<unknown>(null);

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    const lines = [form.line1.trim(), form.line2.trim()].filter(Boolean);
    const body = {
        name: form.name.trim(),
        legal_name: form.legal_name.trim() || null,
        country: form.country || null,
        currency: form.currency || null,
        address: lines.length || form.city || form.zip
            ? { lines, city: form.city.trim() || null, state: form.state.trim() || null, zip: form.zip.trim() || null }
            : null,
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
    const fields = ['name', 'legal_name', 'country', 'currency', 'email', 'tax_id', 'vat_number'];

    return saveError.value && !fields.some((field) => fieldError(saveError.value, field)) ? errorMessage(saveError.value) : null;
});
</script>

<template>
    <SubScreen :title="isNew ? 'Новий клієнт' : existing?.name ?? 'Клієнт'" :back="isNew ? { name: 'clients' } : { name: 'client', params: { id: clientId } }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <TextField v-model="form.name" label="Назва" placeholder="Brightline Labs LLC" hint="Так клієнт називається в інвойсах і списках" :error="fieldError(saveError, 'name')" />
            <TextField v-model="form.legal_name" label="Юридична назва" placeholder="Brightline Labs, LLC" :error="fieldError(saveError, 'legal_name')" />
            <div class="grid grid-cols-2 gap-2.5">
                <SelectField v-model="form.country" label="Країна" :options="countries" :error="fieldError(saveError, 'country')" />
                <SelectField
                    v-model="form.currency"
                    label="Валюта"
                    :options="CURRENCIES.map((currency) => ({ value: currency, label: currency }))"
                    :error="fieldError(saveError, 'currency')"
                />
            </div>

            <section class="flex flex-col gap-3">
                <SectionTitle>Адреса для інвойсу</SectionTitle>
                <TextField v-model="form.line1" label="Адреса" placeholder="500 Market St, Suite 200" />
                <TextField v-model="form.line2" label="Адреса, рядок 2" placeholder="Необов'язково" />
                <div class="grid grid-cols-2 gap-2.5">
                    <TextField v-model="form.city" label="Місто" />
                    <TextField v-model="form.state" label="Штат / регіон" placeholder="Необов'язково" />
                </div>
                <TextField v-model="form.zip" label="Поштовий індекс" class="num" />
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>Податкові дані</SectionTitle>
                <div class="grid grid-cols-2 gap-2.5">
                    <TextField v-model="form.tax_id" label="Податковий номер" class="num" placeholder="EIN" :error="fieldError(saveError, 'tax_id')" />
                    <TextField v-model="form.vat_number" label="VAT" class="num" placeholder="Необов'язково" :error="fieldError(saveError, 'vat_number')" />
                </div>
                <TextField v-model="form.email" label="Email для інвойсів" type="email" :error="fieldError(saveError, 'email')" />
            </section>

            <TextAreaField v-model="form.invoice_footer_note" label="Примітка в інвойсі" :rows="2" placeholder="Друкується під інвойсом цього клієнта" />
            <TextAreaField v-model="form.notes" label="Внутрішня примітка" :rows="2" placeholder="Не друкується" />

            <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </LoadState>

        <template #footer>
            <PrimaryButton :busy="saving" :disabled="loading || !form.name.trim()" @click="save">Зберегти</PrimaryButton>
        </template>
    </SubScreen>
</template>
