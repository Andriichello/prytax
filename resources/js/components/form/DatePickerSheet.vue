<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';
import { calendarWeeks, dateOf, dateParts, daysInMonth, formatDate, formatMonthYear, monthName, today, weekdayNames } from '@/utils/dates';

/**
 * The calendar of a date field, in a bottom sheet: the days of a month, Monday first, with today ringed and the chosen
 * day filled; the month's title turns to the months of a year, for a jump to another month or year. Days outside
 * `min`–`max` can't be picked, and the arrows stop at them. Picking a day, or «Сьогодні», closes the sheet. Both
 * views take the height of six weeks, so the sheet doesn't jump from month to month.
 */
const { title, value = '', min = undefined, max = undefined } = defineProps<{
    title: string;
    /** The chosen date, `YYYY-MM-DD`, or '' without one. */
    value?: string;
    min?: string;
    max?: string;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ choose: [date: string] }>();

const view = ref<'days' | 'months'>('days');
const shown = ref({ year: 2026, month: 1 });
const todayDate = ref(today());

const allowed = (date: string): boolean => (!min || date >= min) && (!max || date <= max);

/* Each opening shows the chosen day's month, else today's, held within the range */
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    todayDate.value = today();

    const start = value || (min && todayDate.value < min ? min : max && todayDate.value > max ? max : todayDate.value);
    const { year, month } = dateParts(start);

    shown.value = { year, month };
    view.value = 'days';
}, { immediate: true });

/** Whether a day of the month can be picked. */
const monthAllowed = (year: number, month: number): boolean => (!max || dateOf(year, month, 1) <= max)
    && (!min || dateOf(year, month, daysInMonth(year, month)) >= min);

/** Whether a day of the year can be picked. */
const yearAllowed = (year: number): boolean => (!max || `${year}-01-01` <= max) && (!min || `${year}-12-31` >= min);

/** The month or year an arrow goes to. */
function shifted(delta: number): { year: number; month: number } {
    if (view.value === 'months') {
        return { year: shown.value.year + delta, month: shown.value.month };
    }

    const index = shown.value.year * 12 + shown.value.month - 1 + delta;

    return { year: Math.floor(index / 12), month: (index % 12) + 1 };
}

const canStep = (delta: number): boolean => {
    const { year, month } = shifted(delta);

    return view.value === 'months' ? yearAllowed(year) : monthAllowed(year, month);
};

function step(delta: number): void {
    if (canStep(delta)) {
        shown.value = shifted(delta);
    }
}

/** Always six weeks, so the sheet keeps its height from month to month. */
const weeks = computed(() => {
    const rows = calendarWeeks(shown.value.year, shown.value.month);

    while (rows.length < 6) {
        rows.push(Array.from({ length: 7 }, () => null));
    }

    return rows;
});
const weekdays = computed(() => weekdayNames());
const months = computed(() => Array.from({ length: 12 }, (_, index) => ({ month: index + 1, name: monthName(index + 1).slice(0, 3) })));

const selectedMonth = computed(() => (value ? dateParts(value) : null));
const currentMonth = computed(() => dateParts(todayDate.value));

function choose(date: string): void {
    if (allowed(date)) {
        emit('choose', date);
        open.value = false;
    }
}

function chooseMonth(month: number): void {
    shown.value = { year: shown.value.year, month };
    view.value = 'days';
}

const dayClass = (date: string): string => {
    if (!allowed(date)) {
        return 'text-switch-off';
    }

    if (date === value) {
        return 'bg-accent font-semibold text-white';
    }

    return date === todayDate.value ? 'border-2 border-accent font-semibold text-accent' : 'text-ink hover:bg-accent-soft';
};

const monthClass = (month: number): string => {
    const { year } = shown.value;

    if (!monthAllowed(year, month)) {
        return 'border border-dashed border-line-input text-switch-off';
    }

    if (selectedMonth.value?.year === year && selectedMonth.value.month === month) {
        return 'border-2 border-accent bg-accent-soft font-semibold text-accent-hover';
    }

    return currentMonth.value.year === year && currentMonth.value.month === month
        ? 'border-2 border-line-input bg-surface font-semibold'
        : 'border border-line-input bg-surface';
};
</script>

<template>
    <BottomSheet v-model:open="open" :title="title">
        <div class="flex flex-col gap-3">
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    :aria-label="view === 'days' ? t('shell.dateField.previousMonth') : t('shell.dateField.previousYear')"
                    :disabled="!canStep(-1)"
                    class="flex size-11 items-center justify-center rounded-full text-ink disabled:text-handle"
                    @click="step(-1)"
                >
                    <Icon name="chevronLeft" :size="22" />
                </button>
                <button
                    type="button"
                    :aria-label="t('shell.dateField.chooseMonth')"
                    :aria-expanded="view === 'months' ? 'true' : 'false'"
                    class="flex h-11 flex-1 items-center justify-center gap-1 rounded-full text-17 font-semibold"
                    aria-live="polite"
                    @click="view = view === 'days' ? 'months' : 'days'"
                >
                    {{ view === 'days' ? formatMonthYear(shown.year, shown.month) : shown.year }}
                    <Icon name="chevronDown" :size="18" class="text-ink-3 transition-transform" :class="view === 'months' ? 'rotate-180' : ''" />
                </button>
                <button
                    type="button"
                    :aria-label="view === 'days' ? t('shell.dateField.nextMonth') : t('shell.dateField.nextYear')"
                    :disabled="!canStep(1)"
                    class="flex size-11 items-center justify-center rounded-full text-ink disabled:text-handle"
                    @click="step(1)"
                >
                    <Icon name="chevronRight" :size="22" />
                </button>
            </div>

            <div v-if="view === 'days'" class="flex h-78 flex-col gap-1">
                <div class="grid grid-cols-7" aria-hidden="true">
                    <span
                        v-for="(name, index) in weekdays"
                        :key="name"
                        class="py-1 text-center text-12 font-medium"
                        :class="index >= 5 ? 'text-ink-4' : 'text-ink-3'"
                    >{{ name }}</span>
                </div>
                <div v-for="(week, index) in weeks" :key="index" class="grid h-11 grid-cols-7">
                    <span v-for="(date, day) in week" :key="date ?? `blank-${day}`" class="flex justify-center">
                        <button
                            v-if="date"
                            type="button"
                            :disabled="!allowed(date)"
                            :aria-label="formatDate(date)"
                            :aria-pressed="date === value ? 'true' : 'false'"
                            :aria-current="date === todayDate ? 'date' : undefined"
                            class="num flex size-11 items-center justify-center rounded-full text-16 disabled:cursor-not-allowed"
                            :class="dayClass(date)"
                            @click="choose(date)"
                        >
                            {{ dateParts(date).day }}
                        </button>
                    </span>
                </div>
            </div>

            <div v-else class="grid h-78 grid-cols-3 grid-rows-4 gap-2">
                <button
                    v-for="item in months"
                    :key="item.month"
                    type="button"
                    :disabled="!monthAllowed(shown.year, item.month)"
                    :aria-pressed="selectedMonth?.year === shown.year && selectedMonth.month === item.month ? 'true' : 'false'"
                    class="flex items-center justify-center rounded-control text-15"
                    :class="monthClass(item.month)"
                    @click="chooseMonth(item.month)"
                >
                    {{ item.name }}
                </button>
            </div>

            <button
                v-if="allowed(todayDate)"
                type="button"
                class="flex h-12 items-center justify-center gap-2 rounded-button border border-line-input bg-surface text-15 font-medium text-accent"
                @click="choose(todayDate)"
            >
                {{ t('shell.dateField.today') }} · <span class="num">{{ formatDate(todayDate) }}</span>
            </button>
        </div>
    </BottomSheet>
</template>
