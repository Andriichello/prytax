<script setup lang="ts">
/**
 * An empty list or section: a card with a one-line explanation and an optional primary action (the default slot).
 */
const { text } = defineProps<{ text: string }>();
</script>

<template>
    <div class="flex flex-col items-start gap-3 rounded-card border border-line bg-surface p-4">
        <p class="m-0 text-14 text-ink-2">{{ text }}</p>
        <slot />
    </div>
</template>
