<script setup lang="ts" generic="T extends string | number">
/**
 * A segmented control: a sunk track, the selected item white with a soft shadow.
 */
const { options, label, size = 'md', disabled = false } = defineProps<{
    options: { value: T; label: string }[];
    /** The group's name for screen readers. */
    label: string;
    size?: 'md' | 'lg';
    /** Shown as it is, for people who can't change it. */
    disabled?: boolean;
}>();

const model = defineModel<T>({ required: true });
</script>

<template>
    <div role="group" :aria-label="label" class="flex gap-1 rounded-control bg-sunk p-1">
        <button
            v-for="option in options"
            :key="String(option.value)"
            type="button"
            :aria-pressed="model === option.value ? 'true' : 'false'"
            :disabled="disabled"
            class="flex flex-1 items-center justify-center rounded-seg px-1 whitespace-nowrap"
            :class="[
                size === 'lg' ? 'h-11 text-15' : 'h-10 text-14',
                model === option.value ? 'bg-surface font-semibold text-ink shadow-seg' : 'text-ink-2',
            ]"
            @click="model = option.value"
        >
            {{ option.label }}
        </button>
    </div>
</template>
