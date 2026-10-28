<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import DatePickerSheet from '@/components/form/DatePickerSheet.vue';
import { t } from '@/i18n';
import { formatDate, parseDisplayDate } from '@/utils/dates';

/**
 * A labelled date (design.md §16.6): a text field typed the Ukrainian way (`дд.мм.рррр`, whatever the device's
 * language), whose tap opens the date picker (`DatePickerSheet`); Alt+↓ opens it from the keyboard. A touch doesn't
 * focus the field, so the phone's keyboard stays down while the picker is open, and the field is ringed in accent
 * meanwhile. The value is `YYYY-MM-DD`, or '' while the text is empty or not a date. `pickerNote` tells under the
 * picker's grid why days can't be picked.
 */
const {
    label,
    error = undefined,
    hint = undefined,
    min = undefined,
    max = undefined,
    required = false,
    disabled = false,
    pickerNote = undefined,
} = defineProps<{
    label: string;
    error?: string;
    hint?: string;
    min?: string;
    max?: string;
    required?: boolean;
    disabled?: boolean;
    pickerNote?: string;
}>();

const model = defineModel<string>({ default: '' });

const id = useId();
const text = ref(formatDate(model.value));
const pickerOpen = ref(false);
const touched = ref(false);

/* A value set from outside (a default, a reset, the calendar) shows as text; the text being typed keeps its form */
watch(model, (value) => {
    if ((parseDisplayDate(text.value) ?? '') !== value) {
        text.value = formatDate(value);
    }
});

function typed(event: Event): void {
    text.value = (event.target as HTMLInputElement).value;
    model.value = parseDisplayDate(text.value) ?? '';
}

function left(): void {
    touched.value = true;

    if (model.value) {
        text.value = formatDate(model.value);
    }
}

function picked(date: string): void {
    model.value = date;
    text.value = formatDate(date);
}

/**
 * A touch or a pen opens the picker without focusing the field, which would raise the phone's keyboard; the field
 * typed in before lets go of the focus, so the keyboard doesn't come back for it when the picker closes.
 */
function pressed(event: PointerEvent): void {
    if (event.pointerType !== 'mouse') {
        event.preventDefault();
        (document.activeElement as HTMLElement | null)?.blur();
    }
}

function openPicker(): void {
    if (!disabled) {
        pickerOpen.value = true;
    }
}

function keyed(event: KeyboardEvent): void {
    if (event.altKey && event.key === 'ArrowDown') {
        event.preventDefault();
        openPicker();
    }
}

/** What is wrong with the typed date, once the field was left: its form, or its range. */
const ownError = computed(() => {
    if (!touched.value || !text.value.trim()) {
        return undefined;
    }

    if (!model.value) {
        return t('shell.dateField.invalid');
    }

    if (min && model.value < min) {
        return t('shell.dateField.notBefore', { date: formatDate(min) });
    }

    if (max && model.value > max) {
        return t('shell.dateField.notAfter', { date: formatDate(max) });
    }

    return undefined;
});

const shownError = computed(() => error ?? ownError.value);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1.5">
        <label :for="id" class="text-13 font-medium text-ink-2">{{ label }}</label>
        <input
            :id="id"
            :value="text"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            :placeholder="t('shell.dateField.placeholder')"
            :required="required"
            :disabled="disabled"
            aria-haspopup="dialog"
            :aria-expanded="pickerOpen ? 'true' : 'false'"
            :aria-invalid="shownError ? 'true' : undefined"
            :aria-describedby="shownError || hint ? `${id}-note` : undefined"
            class="num h-13 w-full min-w-0 rounded-control bg-surface px-3.5 text-16 placeholder:text-ink-4 disabled:bg-muted"
            :class="pickerOpen ? 'border-2 border-accent px-[13px]' : shownError ? 'border border-negative' : 'border border-line-input'"
            @input="typed"
            @blur="left"
            @pointerdown="pressed"
            @click="openPicker"
            @keydown="keyed"
        >
        <p v-if="shownError || hint" :id="`${id}-note`" class="m-0 text-12" :class="shownError ? 'text-negative' : 'text-ink-3'">
            {{ shownError ?? hint }}
        </p>
        <DatePickerSheet v-model:open="pickerOpen" :title="label" :value="model" :min="min" :max="max" :note="pickerNote" @choose="picked" />
    </div>
</template>
