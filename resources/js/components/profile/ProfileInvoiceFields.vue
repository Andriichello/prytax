<script setup lang="ts">
import SelectField from '@/components/form/SelectField.vue';
import { DOCUMENT_LOCALES, PROFILE_CURRENCIES, type ProfileForm } from '@/components/profile/ProfileFormModel';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { t } from '@/i18n';

/**
 * The invoices of a ФОП: their default currency, and the document's language, which is «Документ інвойсу»'s too.
 * Only owners change them (`disabled` for an accountant, who opens the group for the signature).
 */
const { disabled = false } = defineProps<{ disabled?: boolean }>();

const form = defineModel<ProfileForm>({ required: true });
</script>

<template>
    <SelectField
        v-model="form.default_currency"
        :label="t('core.common.currency')"
        :disabled="disabled"
        :options="PROFILE_CURRENCIES.map((currency) => ({ value: currency, label: currency }))"
    />
    <div class="flex flex-col gap-1.5">
        <span class="text-13 font-medium text-ink-2">{{ t('settings.profile.documentLanguage') }}</span>
        <SegmentedControl v-model="form.locale" :label="t('settings.profile.documentLanguage')" size="lg" :disabled="disabled" :options="DOCUMENT_LOCALES" />
        <span class="text-12 text-ink-3">{{ t('settings.profile.documentLanguageHint') }}</span>
    </div>
</template>
