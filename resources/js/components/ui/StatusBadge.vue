<script setup lang="ts">
import type { Tone } from '@/utils/statuses';

/**
 * A small status label: 12 px semibold on a soft background of its tone.
 */
const { label, tone = 'neutral', size = 'md' } = defineProps<{
    label: string;
    tone?: Tone;
    size?: 'sm' | 'md';
}>();
</script>

<template>
    <span
        class="shrink-0 font-semibold whitespace-nowrap"
        :class="{
            'rounded-badge px-2 py-[3px] text-12': size === 'md',
            'rounded-md px-1.5 py-0.5 text-11': size === 'sm',
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
