<script setup lang="ts">
import { t } from '@/i18n';

/**
 * «Без інвойсу»: the marker of a payment without an invoice (design.md §4.2), which stands in place of an invoice's
 * number: white, with a dashed border.
 */
const { size = 'md' } = defineProps<{ size?: 'sm' | 'md' }>();
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center self-start rounded-md border border-dashed border-ink-3 bg-surface font-semibold whitespace-nowrap text-ink-2"
        :class="size === 'sm' ? 'px-1.5 py-px text-11' : 'px-2 py-0.5 text-13'"
    >
        {{ t('invoices.virtual.marker') }}
    </span>
</template>
