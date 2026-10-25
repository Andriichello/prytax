<script setup lang="ts">
import type { ClientForm } from '@/components/client/ClientFormModel';
import TextField from '@/components/form/TextField.vue';
import { t } from '@/i18n';

/**
 * The tax numbers of a client, which its invoices print, and the e-mail for invoices.
 */
const { errors = {} } = defineProps<{ errors?: { tax_id?: string; vat_number?: string; email?: string } }>();

const form = defineModel<ClientForm>({ required: true });
</script>

<template>
    <div class="grid grid-cols-2 gap-2.5">
        <TextField v-model="form.tax_id" :label="t('clients.detail.taxNumber')" class="num" placeholder="EIN" :error="errors.tax_id" />
        <TextField v-model="form.vat_number" label="VAT" class="num" :placeholder="t('core.common.optional')" :error="errors.vat_number" />
    </div>
    <TextField v-model="form.email" :label="t('clients.detail.invoiceEmail')" type="email" :error="errors.email" />
</template>
