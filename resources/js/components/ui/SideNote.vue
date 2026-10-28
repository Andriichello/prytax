<script setup lang="ts">
import { t } from '@/i18n';

/**
 * The side note of a desktop settings page (design.md §17.2): «Як це працює», what the page changes, and, on the
 * accordion pages, that one group is open at a time.
 */
const { text, accordion = true } = defineProps<{
    text: string;
    accordion?: boolean;
}>();
</script>

<template>
    <h2 class="m-0 text-14 font-semibold">{{ t('settings.sideNotes.howItWorks') }}</h2>
    <p class="m-0 text-13 leading-[1.5] text-ink-2">{{ text }}</p>
    <p v-if="accordion" class="m-0 text-13 leading-[1.5] text-ink-2">{{ t('settings.sideNotes.oneGroup') }}</p>
</template>
