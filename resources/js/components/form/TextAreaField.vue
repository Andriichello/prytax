<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

/**
 * A labelled multi-line text, with an optional note on the label's right ("з шаблону договору") and a hint under it.
 * One that grows (`autogrow`) is as high as its text, or its placeholder while it is empty, from 2 rows up to 8, and
 * scrolls past them.
 */
const {
    label,
    rows = 3,
    placeholder = undefined,
    error = undefined,
    hint = undefined,
    aside = undefined,
    disabled = false,
    autogrow = false,
    maxlength = undefined,
} = defineProps<{
    label: string;
    rows?: number;
    placeholder?: string;
    error?: string;
    hint?: string;
    aside?: string;
    disabled?: boolean;
    autogrow?: boolean;
    maxlength?: number;
}>();

const model = defineModel<string>({ default: '' });

const id = useId();
const textarea = ref<HTMLTextAreaElement | null>(null);

/** The rows a growing field shows at least and at most. */
const MIN_ROWS = 2;
const MAX_ROWS = 8;

/** Fit a growing field to its text, or to its placeholder while it is empty. */
function fit(): void {
    const element = textarea.value;

    if (!autogrow || !element) {
        return;
    }

    const style = getComputedStyle(element);
    const line = parseFloat(style.lineHeight);
    const padding = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
    const border = parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
    const measured = element.value === '' && placeholder ? placeholder : null;

    element.style.height = '0px';

    if (measured !== null) {
        element.value = measured;
    }

    const needed = Math.ceil((element.scrollHeight - padding) / line - 0.01);

    if (measured !== null) {
        element.value = '';
    }

    const shown = Math.min(Math.max(needed, MIN_ROWS), MAX_ROWS);
    element.style.height = `${shown * line + padding + border}px`;
    element.style.overflowY = needed > MAX_ROWS ? 'auto' : 'hidden';
}

watch(() => [model.value, placeholder], () => nextTick(fit));

let observer: ResizeObserver | null = null;
let width = 0;

onMounted(() => {
    fit();

    if (autogrow && textarea.value && typeof ResizeObserver !== 'undefined') {
        // A narrower field wraps into more lines
        observer = new ResizeObserver(([entry]) => {
            if (entry && entry.contentRect.width !== width) {
                width = entry.contentRect.width;
                fit();
            }
        });
        observer.observe(textarea.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div class="flex items-baseline justify-between gap-2">
            <label :for="id" class="text-13 font-medium text-ink-2">{{ label }}</label>
            <span v-if="aside" class="text-12 text-ink-3">{{ aside }}</span>
        </div>
        <textarea
            :id="id"
            ref="textarea"
            v-model="model"
            :rows="autogrow ? MIN_ROWS : rows"
            :placeholder="placeholder"
            :disabled="disabled"
            :maxlength="maxlength"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error || hint ? `${id}-note` : undefined"
            class="resize-none rounded-control border bg-surface px-3.5 py-3 text-16 leading-[1.45] placeholder:text-ink-3 disabled:bg-muted"
            :class="error ? 'border-negative' : 'border-line-input'"
        />
        <p v-if="error || hint" :id="`${id}-note`" class="m-0 text-12" :class="error ? 'text-negative' : 'text-ink-3'">{{ error ?? hint }}</p>
    </div>
</template>
