<script setup lang="ts">
/**
 * The caption of a section: 13 px semibold uppercase, with an optional note on the right (the default slot of
 * `aside`). With `rule`, a line runs from the caption to the edge, as the root screens' sections have it (Облік,
 * Податки).
 */
const { as = 'h2', rule = false } = defineProps<{ as?: 'h2' | 'h3' | 'span'; rule?: boolean }>();
</script>

<template>
    <div class="flex justify-between px-0.5" :class="rule ? 'items-center gap-3' : 'items-baseline gap-2'">
        <component :is="as" class="m-0 text-13 font-semibold tracking-[0.04em] whitespace-nowrap text-ink-3 uppercase">
            <slot />
        </component>
        <span v-if="rule" class="h-px flex-1 bg-line-input" aria-hidden="true" />
        <span v-if="$slots.aside" class="text-13 text-ink-3"><slot name="aside" /></span>
    </div>
</template>
