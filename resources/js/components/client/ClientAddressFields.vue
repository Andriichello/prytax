<script setup lang="ts">
import type { ClientForm } from '@/components/client/ClientFormModel';
import TextAreaField from '@/components/form/TextAreaField.vue';
import { t } from '@/i18n';

/**
 * The address of a client, as its invoices print it: as typed, line by line, the country too when it should print.
 */
const form = defineModel<ClientForm>({ required: true });
</script>

<template>
    <TextAreaField
        v-model="form.address"
        :label="t('core.common.address')"
        :rows="4"
        autogrow
        :maxlength="1000"
        :placeholder="'500 Market St, Suite 200\nSan Francisco, CA 94105\nUnited States'"
        :hint="t('clients.form.addressHint')"
    />
</template>
