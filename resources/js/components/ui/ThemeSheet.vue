<script setup lang="ts">
import BottomSheet from '@/components/ui/BottomSheet.vue';
import ThemeOptions from '@/components/ui/ThemeOptions.vue';
import { t } from '@/i18n';

/**
 * «Тема» (M-AppTheme, M-LoginTheme): the themes (`ThemeOptions`) in a sheet, which a choice closes.
 */
const { note } = defineProps<{ note: string }>();

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <BottomSheet v-model:open="open" :title="t('shell.more.appTheme')">
        <template #action><span /></template>
        <ThemeOptions :note="note" @chosen="open = false" />
    </BottomSheet>
</template>
