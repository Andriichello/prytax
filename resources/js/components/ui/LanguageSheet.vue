<script setup lang="ts">
import BottomSheet from '@/components/ui/BottomSheet.vue';
import LanguageOptions from '@/components/ui/LanguageOptions.vue';
import { t } from '@/i18n';

/**
 * «Мова застосунку» (M-AppLanguage, M-LoginLanguage; design.md §16.6): the languages (`LanguageOptions`) in a sheet,
 * which a choice closes.
 */
const { note } = defineProps<{ note: string }>();

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <BottomSheet v-model:open="open" :title="t('shell.more.appLanguage')">
        <template #action><span /></template>
        <LanguageOptions :note="note" @chosen="open = false" />
    </BottomSheet>
</template>
