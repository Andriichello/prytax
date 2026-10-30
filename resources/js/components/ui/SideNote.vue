<script setup lang="ts">
import { t } from '@/i18n';

/**
 * The note of a desktop page (design.md §17.2), at the end of its column: «Як це працює» and what the page changes. An
 * accordion page says that one group is open at a time under its groups, so the note doesn't repeat it.
 */
const { text } = defineProps<{
    text: string;
}>();
</script>

<template>
    <h2 class="m-0 text-14 font-semibold">{{ t('settings.sideNotes.howItWorks') }}</h2>
    <p class="m-0 text-13 leading-[1.5] text-ink-2">{{ text }}</p>
</template>
