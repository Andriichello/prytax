<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * «✎ Змінити» of a record on desktop (design.md §17.6): at the end of the row of the record's title, which it changes,
 * as the record panel's header has it. Phones keep «Змінити» in the header bar.
 */
const { to } = defineProps<{ to: RouteLocationRaw }>();
</script>

<template>
    <RouterLink
        :to="to"
        class="flex h-10 shrink-0 items-center gap-2 rounded-control border border-line-input bg-surface px-3.5 text-14 font-medium whitespace-nowrap text-ink no-underline hover:text-ink"
    >
        <Icon name="pen" :size="16" /> {{ t('core.common.edit') }}
    </RouterLink>
</template>
