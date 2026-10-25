<script setup lang="ts">
import { computed } from 'vue';
import SelectField from '@/components/form/SelectField.vue';
import { MONEY_FORMATS, type ProfileForm } from '@/components/profile/ProfileFormModel';
import { t } from '@/i18n';
import { formatAmount } from '@/utils/money';

/**
 * The money formats of a ФОП: the app's and the invoices' (PDF), each with a sample.
 */
const form = defineModel<ProfileForm>({ required: true });

const sample = (format: ProfileForm['display_format'], amount: string): string => formatAmount(amount, { format });

/** The app's formats, the default one marked. */
const displayFormats = computed(() => MONEY_FORMATS.map((format) => ({
    value: format.value,
    label: format.value === 'space_dot' ? t('settings.profile.defaultFormat', { format: format.label }) : format.label,
})));
</script>

<template>
    <SelectField v-model="form.display_format" :label="t('settings.profile.inApp')" :options="displayFormats" />
    <SelectField v-model="form.invoice_format" :label="t('settings.profile.onInvoices')" :options="MONEY_FORMATS" />
    <div class="flex flex-col gap-1 rounded-control bg-muted px-3.5 py-3 text-14 text-ink-2">
        <span class="flex justify-between gap-3">{{ t('settings.profile.appLabel') }} <span class="num font-semibold text-ink">{{ sample(form.display_format, '1840135.05') }} ₴</span></span>
        <span class="flex justify-between gap-3">{{ t('settings.profile.invoiceLabel') }} <span class="num font-semibold text-ink">{{ sample(form.invoice_format, '3892.50') }} {{ form.default_currency }}</span></span>
    </div>
    <span class="text-12 text-ink-3">{{ t('settings.profile.moneyFormatHint') }}</span>
</template>
