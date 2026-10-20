<script setup lang="ts" generic="T extends string | number">
import { useId } from 'vue';
import Icon from '@/components/ui/Icon.vue';

interface SelectOption<V> {
    value: V;
    label: string;
    disabled?: boolean;
}

/**
 * A labelled native select (the phone's own picker), 52 px tall with a chevron.
 */
const {
    label,
    options,
    placeholder = undefined,
    error = undefined,
    hint = undefined,
    disabled = false,
} = defineProps<{
    label: string;
    options: SelectOption<T>[];
    placeholder?: string;
    error?: string;
    hint?: string;
    disabled?: boolean;
}>();

const model = defineModel<T | null>({ required: true });

const id = useId();

function onChange(event: Event): void {
    const index = (event.target as HTMLSelectElement).selectedIndex - (placeholder !== undefined ? 1 : 0);

    model.value = index >= 0 ? options[index]?.value ?? null : null;
}
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <label :for="id" class="text-13 font-medium text-ink-2">{{ label }}</label>
        <div class="relative">
            <select
                :id="id"
                :disabled="disabled"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="error || hint ? `${id}-note` : undefined"
                class="h-13 w-full appearance-none truncate rounded-control border bg-surface pr-10 pl-3.5 text-16 disabled:bg-muted disabled:text-ink-3"
                :class="error ? 'border-negative' : 'border-line-input'"
                @change="onChange"
            >
                <option v-if="placeholder !== undefined" value="" :selected="model === null" disabled>{{ placeholder }}</option>
                <option
                    v-for="option in options"
                    :key="String(option.value)"
                    :selected="option.value === model"
                    :disabled="option.disabled"
                >
                    {{ option.label }}
                </option>
            </select>
            <Icon name="chevronDown" :size="18" class="pointer-events-none absolute top-[17px] right-3.5 text-ink-2" />
        </div>
        <p v-if="error || hint" :id="`${id}-note`" class="m-0 text-12" :class="error ? 'text-negative' : 'text-ink-3'">
            {{ error ?? hint }}
        </p>
    </div>
</template>
