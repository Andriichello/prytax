<script setup lang="ts">
import { useId } from 'vue';

/**
 * A labelled date (the phone's own date picker): the value is `YYYY-MM-DD`, or '' when empty.
 */
const { label, error = undefined, hint = undefined, min = undefined, max = undefined, required = false } = defineProps<{
    label: string;
    error?: string;
    hint?: string;
    min?: string;
    max?: string;
    required?: boolean;
}>();

const model = defineModel<string>({ default: '' });

const id = useId();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1.5">
        <label :for="id" class="text-13 font-medium text-ink-2">{{ label }}</label>
        <input
            :id="id"
            v-model="model"
            type="date"
            :min="min"
            :max="max"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error || hint ? `${id}-note` : undefined"
            class="num h-13 w-full min-w-0 rounded-control border bg-surface px-3.5 text-16"
            :class="error ? 'border-negative' : 'border-line-input'"
        >
        <p v-if="error || hint" :id="`${id}-note`" class="m-0 text-12" :class="error ? 'text-negative' : 'text-ink-3'">
            {{ error ?? hint }}
        </p>
    </div>
</template>
