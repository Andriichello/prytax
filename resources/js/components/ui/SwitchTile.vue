<script setup lang="ts">
/**
 * A bordered 48 px tile with a label and a switch (handoff §4): Облік's "Показувати в списку".
 */
const { label } = defineProps<{ label: string }>();

const checked = defineModel<boolean>({ required: true });
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="checked ? 'true' : 'false'"
        class="flex min-h-12 flex-1 basis-0 items-center justify-between gap-2.5 rounded-control border border-line-input bg-surface pr-3 pl-3.5 text-left text-14 leading-tight font-medium"
        @click="checked = !checked"
    >
        {{ label }}
        <span
            aria-hidden="true"
            class="flex h-5.5 w-9.5 shrink-0 rounded-full p-0.5 transition-colors"
            :class="checked ? 'justify-end bg-accent' : 'justify-start bg-switch-off'"
        >
            <span class="size-4.5 rounded-full bg-white shadow-[0_1px_2px_rgb(23_25_31/0.25)]" />
        </span>
    </button>
</template>
