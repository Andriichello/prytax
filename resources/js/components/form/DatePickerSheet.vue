<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import DateCalendar from '@/components/form/DateCalendar.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { t } from '@/i18n';
import { addDays, presetRange, rangeDays, RANGE_PRESETS, tapRange, type PickedRange, type RangePreset } from '@/utils/datePicker';
import { dateParts, formatDate, formatDayMonth, today as todayInKyiv } from '@/utils/dates';

/**
 * The date picker of a field on mobile (design.md §16.6): a bottom sheet titled with the field's label, with a sticky
 * «Готово». A single date has the chips «Сьогодні» and «Вчора»; a period has its presets, the day count and the
 * hint. A tap picks at once, and the field follows live (`choose`, `range`); «Готово» and ✕ only close. Days outside
 * `min`–`max` can't be picked, and `note` says why under the grid («Майбутні дати недоступні — …»).
 */
const { title, mode = 'single', value = '', from = '', to = '', min = undefined, max = undefined, note = undefined } = defineProps<{
    title: string;
    mode?: 'single' | 'range';
    /** The chosen date of a single date, `YYYY-MM-DD`, or ''. */
    value?: string;
    /** The period of a range. */
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

/* Each opening shows the chosen day's month (a period's start), else today's, held within the range */
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

const yesterday = computed(() => addDays(todayDate.value, -1));

function choose(date: string): void {
    if (!allowed(date)) {
        return;
    }

    if (mode === 'single') {
        emit('choose', date);
        shown.value = { year: dateParts(date).year, month: dateParts(date).month };

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
    { date: yesterday.value, label: t('shell.dateField.yesterday') },
].filter((day) => allowed(day.date)));

const summary = computed(() => (picked.value.start && picked.value.end
    ? t('shell.dateField.periodDays', {
        from: formatDayMonth(picked.value.start),
        to: formatDate(picked.value.end),
        days: rangeDays(picked.value.start, picked.value.end),
    })
    : null));

const chipClass = (on: boolean): string => (on ? 'border-ink bg-ink font-medium text-white' : 'border-line-input bg-surface text-ink');
</script>

<template>
    <BottomSheet v-model:open="open" :title="title">
        <div class="flex flex-col gap-3">
            <div v-if="mode === 'single'" class="flex gap-2">
                <button
                    v-for="day in quickDays"
                    :key="day.date"
                    type="button"
                    class="min-h-9 rounded-full border border-line-input bg-surface px-3.5 text-14 text-ink"
                    @click="choose(day.date)"
                >
                    {{ day.label }}
                </button>
            </div>
            <div v-else class="scrollbar-none -mx-4 flex gap-2 overflow-x-auto px-4">
                <button
                    v-for="item in presets"
                    :key="item.preset"
                    type="button"
                    class="min-h-9 shrink-0 rounded-full border px-3.5 text-14 whitespace-nowrap"
                    :class="chipClass(item.on)"
                    @click="applyPreset(item.preset)"
                >
                    {{ item.label }}
                </button>
            </div>

            <DateCalendar
                v-model:shown="shown"
                :selected="mode === 'single' ? value : undefined"
                :range="mode === 'range' ? picked : undefined"
                :min="min"
                :max="max"
                :today="todayDate"
                @pick="choose"
            />

            <div v-if="mode === 'range'" class="flex items-baseline justify-between gap-3 px-0.5">
                <span class="text-14 text-ink-3">{{ t('shell.dateField.period') }}</span>
                <span v-if="summary" class="num text-15 font-semibold">{{ summary }}</span>
                <span v-else class="text-15 font-semibold text-accent">{{ t('shell.dateField.chooseEnd') }}</span>
            </div>
        </div>

        <template #footer>
            <div class="flex flex-col gap-3">
                <p v-if="mode === 'range' || note" class="m-0 text-center text-13 leading-[1.4] text-ink-3">
                    {{ mode === 'range' ? t('shell.dateField.rangeHint') : note }}
                </p>
                <PrimaryButton @click="open = false">{{ t('shell.dateField.done') }}</PrimaryButton>
            </div>
        </template>
    </BottomSheet>
</template>
