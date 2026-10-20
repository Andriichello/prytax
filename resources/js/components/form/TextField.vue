<script setup lang="ts">
import { useId } from 'vue';

/**
 * A labelled input of the forms: 52 px tall, the label above, and the field's error below.
 */
const {
    label,
    type = 'text',
    error = undefined,
    hint = undefined,
    numeric = false,
} = defineProps<{
    label: string;
    type?: string;
    error?: string;
    hint?: string;
    /** Amounts and numbers: the mono font and a decimal keyboard. */
    numeric?: boolean;
}>();

defineOptions({ inheritAttrs: false });

const model = defineModel<string>({ default: '' });

const id = useId();
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <label :for="id" class="text-13 font-medium text-ink-2">{{ label }}</label>
        <input
            :id="id"
            v-model="model"
            :type="type"
            :inputmode="numeric ? 'decimal' : undefined"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error || hint ? `${id}-note` : undefined"
            class="h-13 w-full rounded-control border bg-surface px-3.5 text-16 disabled:bg-muted"
            :class="[error ? 'border-negative' : 'border-line-input', numeric ? 'num' : '']"
            v-bind="$attrs"
        >
        <p v-if="error || hint" :id="`${id}-note`" class="m-0 text-13" :class="error ? 'text-negative' : 'text-ink-3'">
            {{ error ?? hint }}
        </p>
    </div>
</template>
