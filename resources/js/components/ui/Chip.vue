<script setup lang="ts">
/**
 * A pill toggle for filters and groupings: filled ink when selected.
 */
const { selected = false } = defineProps<{ selected?: boolean }>();
</script>

<template>
    <button
        type="button"
        :aria-pressed="selected ? 'true' : 'false'"
        class="flex min-h-10 shrink-0 items-center gap-1.5 rounded-full border px-3.5 text-14 whitespace-nowrap"
        :class="selected ? 'border-ink bg-ink font-medium text-white' : 'border-line-input bg-surface text-ink'"
    >
        <slot />
    </button>
</template>
