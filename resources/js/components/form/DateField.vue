<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';
import { formatDate, parseDisplayDate } from '@/utils/dates';

/**
 * A labelled date, typed the Ukrainian way (`дд.мм.рррр`, whatever the device's language), or picked with the
 * phone's own calendar behind the button. The value is `YYYY-MM-DD`, or '' while the text is empty or not a date.
 */
const { label, error = undefined, hint = undefined, min = undefined, max = undefined, required = false, disabled = false } = defineProps<{
    label: string;
    error?: string;
    hint?: string;
    min?: string;
    max?: string;
    required?: boolean;
    disabled?: boolean;
}>();

const model = defineModel<string>({ default: '' });

const id = useId();
const text = ref(formatDate(model.value));
const picker = ref<HTMLInputElement | null>(null);
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

function picked(event: Event): void {
    model.value = (event.target as HTMLInputElement).value;
    text.value = formatDate(model.value);
}

/** Open the calendar where the browser allows it; elsewhere the tap reaches the date input under the button. */
function openPicker(): void {
    try {
        picker.value?.showPicker();
    } catch {
        // Not supported, or not allowed here: the native input takes the tap itself
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
        <div class="relative">
            <input
                :id="id"
                :value="text"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                :placeholder="t('shell.dateField.placeholder')"
                :required="required"
                :disabled="disabled"
                :aria-invalid="shownError ? 'true' : undefined"
                :aria-describedby="shownError || hint ? `${id}-note` : undefined"
                class="num h-13 w-full min-w-0 rounded-control border bg-surface pr-12 pl-3.5 text-16 placeholder:text-ink-4 disabled:bg-muted"
                :class="shownError ? 'border-negative' : 'border-line-input'"
                @input="typed"
                @blur="left"
            >
            <span v-if="!disabled" class="absolute inset-y-0 right-0 flex w-12 items-center justify-center text-ink-2">
                <Icon name="calendar" :size="20" />
                <input
                    ref="picker"
                    type="date"
                    tabindex="-1"
                    :aria-label="t('shell.dateField.calendar', { label })"
                    :value="model"
                    :min="min"
                    :max="max"
                    class="absolute inset-0 size-full cursor-pointer opacity-0"
                    @click="openPicker"
                    @change="picked"
                >
            </span>
        </div>
        <p v-if="shownError || hint" :id="`${id}-note`" class="m-0 text-12" :class="shownError ? 'text-negative' : 'text-ink-3'">
            {{ shownError ?? hint }}
        </p>
    </div>
</template>
