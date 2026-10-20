<script setup lang="ts">
import { useId } from 'vue';

/**
 * A labelled multi-line text, with an optional note on the label's right ("з шаблону договору").
 */
const { label, rows = 3, placeholder = undefined, error = undefined, aside = undefined, disabled = false } = defineProps<{
    label: string;
    rows?: number;
    placeholder?: string;
    error?: string;
    aside?: string;
    disabled?: boolean;
}>();

const model = defineModel<string>({ default: '' });

const id = useId();
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div class="flex items-baseline justify-between gap-2">
            <label :for="id" class="text-13 font-medium text-ink-2">{{ label }}</label>
            <span v-if="aside" class="text-12 text-ink-3">{{ aside }}</span>
        </div>
        <textarea
            :id="id"
            v-model="model"
            :rows="rows"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-invalid="error ? 'true' : undefined"
            class="resize-none rounded-control border bg-surface px-3.5 py-3 text-16 leading-[1.45] placeholder:text-ink-3 disabled:bg-muted"
            :class="error ? 'border-negative' : 'border-line-input'"
        />
        <p v-if="error" class="m-0 text-12 text-negative">{{ error }}</p>
    </div>
</template>
