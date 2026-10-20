<script setup lang="ts">
import type { Tone } from '@/utils/statuses';

/**
 * A small status label: 12 px semibold on a soft background of its tone.
 */
const { label, tone = 'neutral' } = defineProps<{
    label: string;
    tone?: Tone;
}>();
</script>

<template>
    <span
        class="shrink-0 rounded-badge px-2 py-[3px] text-12 font-semibold whitespace-nowrap"
        :class="{
            'bg-neutral-soft text-ink-2': tone === 'neutral',
            'bg-accent-soft text-accent-hover': tone === 'info',
            'bg-warning-soft text-warning': tone === 'warning',
            'bg-positive-soft text-positive': tone === 'positive',
            'bg-negative-soft text-negative': tone === 'negative',
        }"
    >
        {{ label }}
    </span>
</template>
