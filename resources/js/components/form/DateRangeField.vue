<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import DatePickerSheet from '@/components/form/DatePickerSheet.vue';
import { t } from '@/i18n';
import type { PickedRange } from '@/utils/datePicker';
import { formatDate, parseDisplayDate } from '@/utils/dates';

/**
 * A period of two dates side by side, «з» and «по» (design.md §16.6): each a text field typed `дд.мм.рррр`, and a
 * tap on either opens the period picker (`DatePickerSheet` in its range mode), titled `title`, which fills both
 * live; both are ringed in accent while it is open. Alt+↓ opens it from the keyboard. The values are `YYYY-MM-DD`,
 * or '' while a text is empty or not a date.
 */
const {
    title,
    fromLabel = undefined,
    toLabel = undefined,
    fromError = undefined,
    toError = undefined,
    hint = undefined,
    min = undefined,
    max = undefined,
    required = false,
    disabled = false,
} = defineProps<{
    title: string;
    fromLabel?: string;
    toLabel?: string;
    fromError?: string;
    toError?: string;
    hint?: string;
    min?: string;
    max?: string;
    required?: boolean;
    disabled?: boolean;
}>();

const from = defineModel<string>('from', { default: '' });
const to = defineModel<string>('to', { default: '' });

const id = useId();
const pickerOpen = ref(false);
const texts = ref({ from: formatDate(from.value), to: formatDate(to.value) });
const touched = ref({ from: false, to: false });

/* Values set from outside (a default, a reset, the picker) show as text; a text being typed keeps its form */
watch(from, (value) => {
    if ((parseDisplayDate(texts.value.from) ?? '') !== value) {
        texts.value.from = formatDate(value);
    }
});
watch(to, (value) => {
    if ((parseDisplayDate(texts.value.to) ?? '') !== value) {
        texts.value.to = formatDate(value);
    }
});

function typed(end: 'from' | 'to', event: Event): void {
    texts.value[end] = (event.target as HTMLInputElement).value;

    const value = parseDisplayDate(texts.value[end]) ?? '';

    if (end === 'from') {
        from.value = value;
    } else {
        to.value = value;
    }
}

function left(end: 'from' | 'to'): void {
    touched.value[end] = true;

    const value = end === 'from' ? from.value : to.value;

    if (value) {
        texts.value[end] = formatDate(value);
    }
}

function picked(range: PickedRange): void {
    from.value = range.start;
    to.value = range.end;
    texts.value = { from: formatDate(range.start), to: formatDate(range.end) };
}

/** A touch opens the picker without focusing a field, so the phone's keyboard stays down (as `DateField`). */
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

/** What is wrong with a typed date, once its field was left: its form, the range, or an end before the start. */
function ownError(end: 'from' | 'to'): string | undefined {
    const value = end === 'from' ? from.value : to.value;

    if (!touched.value[end] || !texts.value[end].trim()) {
        return undefined;
    }

    if (!value) {
        return t('shell.dateField.invalid');
    }

    if ((min && value < min) || (end === 'to' && from.value && value < from.value)) {
        return t('shell.dateField.notBefore', { date: formatDate(end === 'to' && from.value ? from.value : min) });
    }

    if (max && value > max) {
        return t('shell.dateField.notAfter', { date: formatDate(max) });
    }

    return undefined;
}

const errors = computed(() => ({ from: fromError ?? ownError('from'), to: toError ?? ownError('to') }));
const note = computed(() => errors.value.from ?? errors.value.to ?? hint);

const inputClass = (end: 'from' | 'to'): string => (pickerOpen.value
    ? 'border-2 border-accent px-[13px]'
    : errors.value[end] ? 'border border-negative' : 'border border-line-input');
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1.5">
        <div class="grid grid-cols-2 gap-3">
            <div v-for="end in (['from', 'to'] as const)" :key="end" class="flex min-w-0 flex-col gap-1.5">
                <label :for="`${id}-${end}`" class="text-13 font-medium text-ink-2">
                    {{ end === 'from' ? (fromLabel ?? t('shell.dateField.from')) : (toLabel ?? t('shell.dateField.to')) }}
                </label>
                <input
                    :id="`${id}-${end}`"
                    :value="texts[end]"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    :placeholder="t('shell.dateField.placeholder')"
                    :required="required"
                    :disabled="disabled"
                    aria-haspopup="dialog"
                    :aria-expanded="pickerOpen ? 'true' : 'false'"
                    :aria-invalid="errors[end] ? 'true' : undefined"
                    :aria-describedby="note ? `${id}-note` : undefined"
                    class="num h-13 w-full min-w-0 rounded-control bg-surface px-3.5 text-16 placeholder:text-ink-4 disabled:bg-muted"
                    :class="inputClass(end)"
                    @input="typed(end, $event)"
                    @blur="left(end)"
                    @pointerdown="pressed"
                    @click="openPicker"
                    @keydown="keyed"
                >
            </div>
        </div>
        <p v-if="note" :id="`${id}-note`" class="m-0 text-12" :class="errors.from || errors.to ? 'text-negative' : 'text-ink-3'">
            {{ note }}
        </p>
        <DatePickerSheet
            v-model:open="pickerOpen"
            mode="range"
            :title="title"
            :from="from"
            :to="to"
            :min="min"
            :max="max"
            @range="picked"
        />
    </div>
</template>
