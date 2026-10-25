<script setup lang="ts">
import { type ClientForm, MAX_LINES } from '@/components/client/ClientFormModel';
import TextField from '@/components/form/TextField.vue';
import { t } from '@/i18n';

/**
 * The address of a client, which its invoices print: up to four lines, the city, the state and the postal code.
 */
const form = defineModel<ClientForm>({ required: true });
</script>

<template>
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
</template>
