<script setup lang="ts">
import { useAccordion } from '@/composables/useAccordion';

/**
 * A block of an accordion form outside its groups (a banner, a switch card, a footer, a hint): it dims and goes inert
 * while a group is open (design.md §16.3).
 */
const { busy } = useAccordion();
</script>

<template>
    <div class="transition-opacity" :class="busy ? 'pointer-events-none opacity-45' : ''" :inert="busy || undefined">
        <slot />
    </div>
</template>
