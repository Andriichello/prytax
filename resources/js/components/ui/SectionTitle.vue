<script setup lang="ts">
/**
 * The caption of a section: 13 px semibold uppercase, with an optional note on the right (the default slot of
 * `aside`).
 */
const { as = 'h2' } = defineProps<{ as?: 'h2' | 'h3' | 'span' }>();
</script>

<template>
    <div class="flex items-baseline justify-between gap-2 px-0.5">
        <component :is="as" class="m-0 text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">
            <slot />
        </component>
        <span v-if="$slots.aside" class="text-13 text-ink-3"><slot name="aside" /></span>
    </div>
</template>
