<script setup lang="ts">
import DateField from '@/components/form/DateField.vue';
import TextField from '@/components/form/TextField.vue';
import type { ProfileForm } from '@/components/profile/ProfileFormModel';
import { t } from '@/i18n';

/**
 * The taxpayer of a ФОП: the full name, in Latin letters for invoices, the РНОКПП (or the passport instead), which is
 * stored encrypted and shown masked, and the registration date.
 */
const { maskedTaxId = '', errors = {} } = defineProps<{
    maskedTaxId?: string;
    errors?: { full_name?: string; full_name_en?: string; tax_id?: string; registered_at?: string };
}>();

const form = defineModel<ProfileForm>({ required: true });
</script>

<template>
    <TextField v-model="form.full_name" :label="t('settings.profile.fullName')" :error="errors.full_name" />
    <TextField v-model="form.full_name_en" :label="t('settings.profile.fullNameLatinHint')" :error="errors.full_name_en" />
    <div class="grid grid-cols-2 gap-2.5">
        <TextField
            v-model="form.tax_id"
            :label="form.passport ? t('settings.profile.passport') : t('settings.profile.taxId')"
            class="num"
            :placeholder="maskedTaxId"
            :error="errors.tax_id"
        />
        <DateField v-model="form.registered_at" :label="t('settings.profile.registrationDate')" :error="errors.registered_at" />
    </div>
    <label class="flex min-h-11 items-center gap-3 text-15 text-ink-2">
        <input v-model="form.passport" type="checkbox" class="size-5 accent-accent">
        {{ t('settings.profile.passportHint') }}
    </label>
    <span class="-mt-2 text-12 text-ink-3">{{ t('settings.profile.taxIdHint') }}</span>
</template>
