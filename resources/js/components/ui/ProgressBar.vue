<script setup lang="ts">
import { computed } from 'vue';

/**
 * A progress or limit bar: cobalt, amber from the warning threshold (80 % for limits) upward.
 */
const { percent, warnFrom = null, height = 6 } = defineProps<{
    /** 0–100; values above 100 fill the bar. */
    percent: number;
    /** The percentage from which the bar turns amber, or null to stay cobalt. */
    warnFrom?: number | null;
    height?: number;
}>();

const width = computed(() => `${Math.min(100, Math.max(0, percent))}%`);
const warns = computed(() => warnFrom !== null && percent >= warnFrom);
</script>

<template>
    <span
        class="flex flex-1 overflow-hidden rounded-full bg-muted"
        :style="{ height: `${height}px` }"
        role="progressbar"
        :aria-valuenow="Math.round(percent)"
        aria-valuemin="0"
        aria-valuemax="100"
    >
        <span class="rounded-full" :class="warns ? 'bg-warning-bar' : 'bg-accent'" :style="{ width }" />
    </span>
</template>
