<script setup lang="ts">
/**
 * A read-only row of a closed accordion group: its muted label, and the value (the default slot, or `value`).
 */
const { label, value = undefined } = defineProps<{ label: string; value?: string | null }>();
</script>

<template>
    <span class="pt-0.5 text-13 text-ink-3">{{ label }}</span>
    <span class="min-w-0 text-15 leading-[1.4] [overflow-wrap:anywhere]"><slot>{{ value || '—' }}</slot></span>
</template>
