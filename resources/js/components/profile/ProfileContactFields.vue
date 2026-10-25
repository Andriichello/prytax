<script setup lang="ts">
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import type { ProfileForm } from '@/components/profile/ProfileFormModel';
import { t } from '@/i18n';

/**
 * The address and contacts of a ФОП, which its invoices print: the address in Ukrainian and in English, the e-mail
 * and the phone.
 */
const { errors = {} } = defineProps<{
    errors?: { address?: string; address_en?: string; email?: string; phone?: string };
}>();

const form = defineModel<ProfileForm>({ required: true });
</script>

<template>
    <TextAreaField v-model="form.address" :label="t('core.common.address')" :rows="2" :error="errors.address" />
    <TextAreaField v-model="form.address_en" :label="t('settings.profile.addressEnglish')" :rows="2" :error="errors.address_en" />
    <TextField v-model="form.email" label="Email" type="email" :error="errors.email" />
    <TextField v-model="form.phone" :label="t('settings.profile.phone')" type="tel" placeholder="+380" :error="errors.phone" />
</template>
