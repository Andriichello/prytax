<script setup lang="ts">
import { CONTRACT_CURRENCIES, type ContractForm } from '@/components/client/ContractFormModel';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import { t } from '@/i18n';

/**
 * The main fields of a contract: its name and number, its subject (the {services} of the payment purpose), when it
 * is valid, its currency and payment terms.
 */
const { errors = {} } = defineProps<{ errors?: Partial<Record<'name' | 'number' | 'subject' | 'started_at' | 'ends_at' | 'payment_terms_days', string>> }>();

const form = defineModel<ContractForm>({ required: true });
</script>

<template>
    <TextField v-model="form.name" :label="t('core.common.name')" placeholder="Service Agreement" :error="errors.name" />
    <TextField v-model="form.number" :label="t('core.common.number')" :placeholder="t('core.common.optional')" :error="errors.number" />
    <TextField
        v-model="form.subject"
        :label="t('clients.contract.subject')"
        placeholder="IT services"
        :hint="t('clients.contract.subjectHint', { placeholder: '{services}' })"
        :error="errors.subject"
    />
    <div class="grid grid-cols-2 gap-2.5">
        <DateField v-model="form.started_at" :label="t('clients.contract.validFrom')" :error="errors.started_at" />
        <DateField v-model="form.ends_at" :label="t('clients.contract.validUntil')" :hint="t('clients.contract.emptyOpenEnded')" :min="form.started_at || undefined" :error="errors.ends_at" />
        <SelectField v-model="form.currency" :label="t('core.common.currency')" :options="CONTRACT_CURRENCIES.map((currency) => ({ value: currency, label: currency }))" />
        <TextField v-model="form.payment_terms_days" :label="t('clients.contract.paymentTermsDays')" inputmode="numeric" class="num" :error="errors.payment_terms_days" />
    </div>
</template>
