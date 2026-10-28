<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import DateCalendar from '@/components/form/DateCalendar.vue';
import Icon from '@/components/ui/Icon.vue';
import Popover from '@/components/ui/Popover.vue';
import { t } from '@/i18n';
import { addDays, addMonths, presetRange, rangeDays, RANGE_PRESETS, tapRange, type PickedRange, type RangePreset } from '@/utils/datePicker';
import { dateParts, formatDate, formatDayMonth, today as todayInKyiv } from '@/utils/dates';

/**
 * The date picker of a field on desktop (D-DatePicker, D-DateRangePicker; design.md §17.4): a popover under the
 * field, kept inside the viewport, which leaves the focus in the field, so it can still be typed in. A single date
 * shows one month, 320 px, with «Сьогодні», «Вчора» and the field's `note` under it; a period, 720 px, has the
 * presets in a column («Швидко»), two months side by side with one pair of arrows, and the day count with «Готово».
 * A click picks at once and the field follows live, as on mobile (`DatePickerSheet`).
 */
const { anchor, title, mode = 'single', value = '', from = '', to = '', min = undefined, max = undefined, note = undefined } = defineProps<{
    anchor: HTMLElement | null;
    title: string;
    mode?: 'single' | 'range';
    value?: string;
    from?: string;
    to?: string;
    min?: string;
    max?: string;
    note?: string;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{
    choose: [date: string];
    range: [range: PickedRange];
}>();

const todayDate = ref(todayInKyiv());
const shown = ref({ year: 2026, month: 1 });
const picked = ref<PickedRange>({ start: '', end: '' });

const allowed = (date: string): boolean => (!min || date >= min) && (!max || date <= max);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    todayDate.value = todayInKyiv();
    picked.value = { start: from, end: to };

    const chosen = mode === 'range' ? from : value;
    const start = chosen || (min && todayDate.value < min ? min : max && todayDate.value > max ? max : todayDate.value);

    shown.value = { year: dateParts(start).year, month: dateParts(start).month };
}, { immediate: true });

/** The second month of a period, after the one shown. */
const next = computed({
    get: () => addMonths(shown.value.year, shown.value.month, 1),
    set: (value: { year: number; month: number }) => {
        shown.value = addMonths(value.year, value.month, -1);
    },
});

function step(delta: number): void {
    shown.value = addMonths(shown.value.year, shown.value.month, delta);
}

function choose(date: string): void {
    if (!allowed(date)) {
        return;
    }

    if (mode === 'single') {
        emit('choose', date);
        open.value = false;

        return;
    }

    picked.value = tapRange(picked.value, date);
    emit('range', picked.value);
}

function applyPreset(preset: RangePreset): void {
    const range = presetRange(preset, todayDate.value);

    picked.value = range;
    shown.value = { year: dateParts(range.start).year, month: dateParts(range.start).month };
    emit('range', range);
}

const presetLabel = (preset: RangePreset): string => ({
    thisMonth: t('shell.dateField.presets.thisMonth'),
    nextMonth: t('shell.dateField.presets.nextMonth'),
    lastMonth: t('shell.dateField.presets.lastMonth'),
    thisQuarter: t('shell.dateField.presets.thisQuarter'),
})[preset];

const presets = computed(() => RANGE_PRESETS.map((preset) => {
    const range = presetRange(preset, todayDate.value);

    return { preset, label: presetLabel(preset), on: range.start === picked.value.start && range.end === picked.value.end };
}));

const quickDays = computed(() => [
    { date: todayDate.value, label: t('shell.dateField.today') },
    { date: addDays(todayDate.value, -1), label: t('shell.dateField.yesterday') },
].filter((day) => allowed(day.date)));

const summary = computed(() => (picked.value.start && picked.value.end
    ? t('shell.dateField.periodDays', { from: formatDayMonth(picked.value.start), to: formatDate(picked.value.end), days: rangeDays(picked.value.start, picked.value.end) })
    : null));

const arrow = 'flex size-8 items-center justify-center rounded-full border border-line-input bg-surface text-ink';
</script>

<template>
    <Popover
        v-model:open="open"
        :anchor="anchor"
        :placement="mode === 'range' ? 'bottom-end' : 'bottom-start'"
        :width="mode === 'range' ? 720 : 320"
        :label="title"
        :focus="false"
    >
        <div v-if="mode === 'single'" class="flex flex-col gap-2 p-3">
            <DateCalendar v-model:shown="shown" :selected="value" :min="min" :max="max" :today="todayDate" compact @pick="choose" />
            <div class="flex items-center gap-3 border-t border-divider pt-2 text-13">
                <button v-for="day in quickDays" :key="day.date" type="button" class="font-medium text-accent" @click="choose(day.date)">{{ day.label }}</button>
                <span v-if="note" class="ml-auto text-right text-12 text-ink-3">{{ note }}</span>
            </div>
        </div>

        <div v-else class="flex flex-col">
            <div class="flex">
                <div class="flex w-42 shrink-0 flex-col gap-1.5 border-r border-divider p-3">
                    <span class="px-1 pb-1 text-12 font-semibold tracking-[0.04em] text-ink-3 uppercase">{{ t('shell.dateField.quick') }}</span>
                    <button
                        v-for="item in presets"
                        :key="item.preset"
                        type="button"
                        class="min-h-8 rounded-control border px-3 text-left text-13"
                        :class="item.on ? 'border-ink bg-ink font-medium text-white' : 'border-line-input bg-surface text-ink'"
                        @click="applyPreset(item.preset)"
                    >
                        {{ item.label }}
                    </button>
                </div>
                <div class="relative grid flex-1 grid-cols-2 gap-4 p-3">
                    <button type="button" :aria-label="t('shell.dateField.previousMonth')" class="absolute top-3.5 left-3" :class="arrow" @click="step(-1)">
                        <Icon name="chevronLeft" :size="16" />
                    </button>
                    <button type="button" :aria-label="t('shell.dateField.nextMonth')" class="absolute top-3.5 right-3" :class="arrow" @click="step(1)">
                        <Icon name="chevronRight" :size="16" />
                    </button>
                    <DateCalendar v-model:shown="shown" :range="picked" :min="min" :max="max" :today="todayDate" :show-navigation="false" compact @pick="choose" />
                    <DateCalendar v-model:shown="next" :range="picked" :min="min" :max="max" :today="todayDate" :show-navigation="false" compact @pick="choose" />
                </div>
            </div>
            <div class="flex items-center gap-4 border-t border-divider px-3 py-2.5">
                <span class="text-13 text-ink-3">{{ t('shell.dateField.period') }}</span>
                <span v-if="summary" class="num text-13 font-semibold">{{ summary }}</span>
                <span v-else class="text-13 font-semibold text-accent">{{ t('shell.dateField.chooseEnd') }}</span>
                <span class="ml-auto text-12 text-ink-3">{{ t('shell.dateField.startThenEnd') }}</span>
                <button type="button" class="h-8 rounded-control bg-accent px-3.5 text-13 font-semibold text-white" @click="open = false">{{ t('shell.dateField.done') }}</button>
            </div>
        </div>
    </Popover>
</template>
