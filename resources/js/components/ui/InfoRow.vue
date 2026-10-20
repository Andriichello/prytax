<script setup lang="ts">
/**
 * A label and its value on one row of a details card; the value is set in mono with `figure`.
 */
const { label, figure = false } = defineProps<{
    label: string;
    figure?: boolean;
}>();
</script>

<template>
    <div class="flex min-h-12 items-center justify-between gap-3 py-2.5">
        <span class="shrink-0 text-15 text-ink-2">{{ label }}</span>
        <span class="min-w-0 text-right text-15" :class="figure ? 'num' : ''"><slot /></span>
    </div>
</template>
