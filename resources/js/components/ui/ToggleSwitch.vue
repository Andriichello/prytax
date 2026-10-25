<script setup lang="ts">
/**
 * A 51 × 31 switch of the «Minimal» cards (design.md §16.3): accent when on.
 */
const { label, disabled = false } = defineProps<{
    /** The accessible name, when no visible label names it. */
    label?: string;
    disabled?: boolean;
}>();

const checked = defineModel<boolean>({ required: true });
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="checked ? 'true' : 'false'"
        :aria-label="label"
        :disabled="disabled"
        class="relative h-7.75 w-12.75 shrink-0 rounded-full transition-colors disabled:opacity-60"
        :class="checked ? 'bg-accent' : 'bg-handle'"
        @click="checked = !checked"
    >
        <span
            aria-hidden="true"
            class="absolute top-0.75 size-6.25 rounded-full bg-white shadow-[0_1px_3px_rgb(23_25_31/0.25)] transition-[left]"
            :class="checked ? 'left-[23px]' : 'left-0.75'"
        />
    </button>
</template>
