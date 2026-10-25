<script setup lang="ts">
import { computed } from 'vue';
import type { Amount } from '@/api/types';

/**
 * A bar of Податки's summary (design.md §16.5): what is paid in green, then what is sent in amber, out of what is
 * accrued, split into the parts of the period (its months or its quarters) by ticks, which overhang it like the 80
 * and 90 % ones of the income limit. On the black band (`dark`) it is 10 px high, else 8 px.
 */
const { accrued, sent, paid, parts, dark = false } = defineProps<{
    accrued: Amount;
    sent: Amount;
    paid: Amount;
    parts: number;
    dark?: boolean;
}>();

/** A share of the accrued amount in percent, unrounded, so a bar never runs past its end. */
const shareOf = (amount: Amount): number => (Number(accrued) > 0 ? Math.max(0, (Number(amount) / Number(accrued)) * 100) : 0);

const paidWidth = computed(() => Math.min(100, shareOf(paid)));
const sentWidth = computed(() => Math.min(100 - paidWidth.value, shareOf(sent)));
const ticks = computed(() => Array.from({ length: Math.max(0, parts - 1) }, (_, index) => ((index + 1) / parts) * 100));
</script>

<template>
    <div class="relative flex" :class="dark ? 'h-2.5 rounded-[5px] bg-divider-ink' : 'mt-0.5 mb-1 h-2 rounded-[4px] bg-muted'" aria-hidden="true">
        <span :class="dark ? 'rounded-l-[5px] bg-positive-on-ink' : 'rounded-l-[4px] bg-positive'" :style="{ width: `${paidWidth}%` }" />
        <span class="bg-sent-bar" :style="{ width: `${sentWidth}%` }" />
        <span
            v-for="tick in ticks"
            :key="tick"
            class="absolute -top-[3px] -ml-px w-0.5"
            :class="dark ? 'h-4 bg-tick-on-ink' : 'h-3.5 bg-tick'"
            :style="{ left: `${tick}%` }"
        />
    </div>
</template>
