<script setup lang="ts">
import { computed } from 'vue';
import type { ClientForm } from '@/components/client/ClientFormModel';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import { locale, t } from '@/i18n';
import { ABBREVIATION_MAX, COMMON_COUNTRIES, countryName } from '@/utils/clients';

/**
 * The main fields of a client: its name on invoices and lists, the abbreviation, which prefixes its invoice numbers
 * (upper case, Latin letters and digits, up to 10), the legal name and the Ukrainian one, and the country.
 */
const { errors = {} } = defineProps<{
    errors?: { name?: string; abbreviation?: string; legal_name?: string; legal_name_uk?: string; country?: string };
}>();

const form = defineModel<ClientForm>({ required: true });
const emit = defineEmits<{ abbreviationTyped: [typed: boolean] }>();

/** The abbreviation as it is typed, in upper case; an emptied one is suggested from the name again. */
const abbreviation = computed({
    get: () => form.value.abbreviation,
    set: (value: string) => {
        form.value.abbreviation = value.toUpperCase();
        emit('abbreviationTyped', value !== '');
    },
});

const countries = computed(() => {
    const codes = COMMON_COUNTRIES.includes(form.value.country) || !form.value.country
        ? COMMON_COUNTRIES
        : [form.value.country, ...COMMON_COUNTRIES];

    return codes
        .map((code) => ({ value: code, label: `${countryName(code)} · ${code}` }))
        .sort((a, b) => a.label.localeCompare(b.label, locale.value));
});
</script>

<template>
    <TextField v-model="form.name" :label="t('core.common.name')" placeholder="Brightline Labs LLC" :hint="t('clients.form.nameHint')" :error="errors.name" />
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
        :error="errors.abbreviation"
    />
    <TextField v-model="form.legal_name" :label="t('clients.form.legalName')" placeholder="Brightline Labs, LLC" :error="errors.legal_name" />
    <TextField
        v-model="form.legal_name_uk"
        :label="t('clients.form.legalNameUk')"
        :placeholder="t('core.common.optional')"
        :hint="t('clients.form.legalNameUkHint')"
        :error="errors.legal_name_uk"
    />
    <SelectField v-model="form.country" :label="t('clients.form.country')" :options="countries" :error="errors.country" />
</template>
