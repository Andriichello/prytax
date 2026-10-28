<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';
import { addDays, addMonths, type PickedRange } from '@/utils/datePicker';
import { calendarWeeks, dateOf, dateParts, daysInMonth, formatDayMonthWords, formatMonthYear, monthName, weekdayNames } from '@/utils/dates';

/**
 * The month grid of the date picker (design.md §16.6): `‹ Жовтень 2026 ›`, the weekdays Monday first, and the days —
 * the chosen one (or a period's ends) filled, a period's days between on a soft band, today ringed, days outside
 * `min`–`max` grey and inert. The month's title turns to the months of its year, for a jump to a far month. The grid
 * is a `role="grid"` with one day in the tab order: the arrow keys move it (across months), Enter picks it.
 */
const { selected = '', range = undefined, min = undefined, max = undefined, today, showNavigation = true, compact = false } = defineProps<{
    /** The chosen day of a single date. */
    selected?: string;
    /** The period of a range. */
    range?: PickedRange;
    min?: string;
    max?: string;
    today: string;
    /** The arrows and the title; a second month of the desktop range shares the first one's. */
    showNavigation?: boolean;
    /** The desktop popover's smaller days (34 px). */
    compact?: boolean;
}>();

/** The month shown. */
const shown = defineModel<{ year: number; month: number }>('shown', { required: true });
const emit = defineEmits<{ pick: [date: string] }>();

const view = ref<'days' | 'months'>('days');
const grid = ref<HTMLElement | null>(null);
const focused = ref(selected || range?.start || today);

const allowed = (date: string): boolean => (!min || date >= min) && (!max || date <= max);

const monthAllowed = (year: number, month: number): boolean => (!max || dateOf(year, month, 1) <= max)
    && (!min || dateOf(year, month, daysInMonth(year, month)) >= min);

const yearAllowed = (year: number): boolean => (!max || `${year}-01-01` <= max) && (!min || `${year}-12-31` >= min);

function shifted(delta: number): { year: number; month: number } {
    return view.value === 'months'
        ? { year: shown.value.year + delta, month: shown.value.month }
        : addMonths(shown.value.year, shown.value.month, delta);
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

/* The day in the tab order stays inside the month shown */
watch(shown, ({ year, month }) => {
    const { year: focusedYear, month: focusedMonth, day } = dateParts(focused.value);

    if (focusedYear !== year || focusedMonth !== month) {
        focused.value = dateOf(year, month, Math.min(day, daysInMonth(year, month)));
    }
});

/** Always six weeks, so the picker keeps its height from month to month. */
const weeks = computed(() => {
    const rows = calendarWeeks(shown.value.year, shown.value.month);

    while (rows.length < 6) {
        rows.push(Array.from({ length: 7 }, () => null));
    }

    return rows;
});
const weekdays = computed(() => weekdayNames());
const months = computed(() => Array.from({ length: 12 }, (_, index) => ({ month: index + 1, name: monthName(index + 1).slice(0, 3) })));

const isEnd = (date: string): boolean => (range ? date === range.start || date === range.end : date === selected);
const isBetween = (date: string): boolean => Boolean(range?.start && range.end && date > range.start && date < range.end);

/** The band under a period's days: whole between the ends, half at each end, so it rounds into their circles. */
function bandClass(date: string): string {
    if (isBetween(date)) {
        return 'bg-accent-soft';
    }

    if (range?.start && range.end && range.start !== range.end) {
        if (date === range.start) {
            return 'bg-[linear-gradient(90deg,transparent_50%,var(--color-accent-soft)_50%)]';
        }

        if (date === range.end) {
            return 'bg-[linear-gradient(90deg,var(--color-accent-soft)_50%,transparent_50%)]';
        }
    }

    return '';
}

function dayClass(date: string): string {
    if (!allowed(date)) {
        return 'text-switch-off';
    }

    if (isEnd(date)) {
        return 'bg-accent font-semibold text-white';
    }

    if (isBetween(date)) {
        return 'text-accent-hover';
    }

    return date === today ? 'font-semibold text-accent shadow-[inset_0_0_0_1.5px_var(--color-accent)]' : 'text-ink hover:bg-accent-soft';
}

function monthClass(month: number): string {
    const { year } = shown.value;

    if (!monthAllowed(year, month)) {
        return 'border border-dashed border-line-input text-switch-off';
    }

    const chosen = selected || range?.start;

    if (chosen && dateParts(chosen).year === year && dateParts(chosen).month === month) {
        return 'border-2 border-accent bg-accent-soft font-semibold text-accent-hover';
    }

    return 'border border-line-input bg-surface';
}

function chooseMonth(month: number): void {
    shown.value = { year: shown.value.year, month };
    view.value = 'days';
}

function pick(date: string): void {
    if (allowed(date)) {
        focused.value = date;
        emit('pick', date);
    }
}

const MOVES: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };

/** The arrow keys move the day in the tab order, turning the month when they leave it. */
async function onKeydown(event: KeyboardEvent): Promise<void> {
    const move = MOVES[event.key];

    if (move === undefined) {
        return;
    }

    event.preventDefault();

    const next = addDays(focused.value, move);
    const { year, month } = dateParts(next);

    focused.value = next;

    if (year !== shown.value.year || month !== shown.value.month) {
        shown.value = { year, month };
    }

    await nextTick();
    grid.value?.querySelector<HTMLButtonElement>(`[data-date="${next}"]`)?.focus();
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div v-if="showNavigation" class="flex items-center justify-between gap-2">
            <button
                type="button"
                :aria-label="view === 'days' ? t('shell.dateField.previousMonth') : t('shell.dateField.previousYear')"
                :disabled="!canStep(-1)"
                class="flex size-9 shrink-0 items-center justify-center rounded-full border border-line-input bg-surface text-ink disabled:border-line disabled:bg-paper disabled:text-switch-off"
                @click="step(-1)"
            >
                <Icon name="chevronLeft" :size="18" />
            </button>
            <button
                type="button"
                :aria-label="t('shell.dateField.chooseMonth')"
                :aria-expanded="view === 'months' ? 'true' : 'false'"
                class="flex h-9 items-center justify-center gap-1 rounded-full px-2 text-16 font-semibold"
                aria-live="polite"
                @click="view = view === 'days' ? 'months' : 'days'"
            >
                {{ view === 'days' ? formatMonthYear(shown.year, shown.month) : shown.year }}
                <Icon name="chevronDown" :size="16" class="text-ink-3 transition-transform" :class="view === 'months' ? 'rotate-180' : ''" />
            </button>
            <button
                type="button"
                :aria-label="view === 'days' ? t('shell.dateField.nextMonth') : t('shell.dateField.nextYear')"
                :disabled="!canStep(1)"
                class="flex size-9 shrink-0 items-center justify-center rounded-full border border-line-input bg-surface text-ink disabled:border-line disabled:bg-paper disabled:text-switch-off"
                @click="step(1)"
            >
                <Icon name="chevronRight" :size="18" />
            </button>
        </div>
        <span v-else class="flex h-9 items-center justify-center font-semibold" :class="compact ? 'text-14' : 'text-16'">{{ formatMonthYear(shown.year, shown.month) }}</span>

        <div
            v-if="view === 'days'"
            ref="grid"
            role="grid"
            :aria-label="formatMonthYear(shown.year, shown.month)"
            class="flex flex-col gap-0.5"
            @keydown="onKeydown"
        >
            <div role="row" class="grid grid-cols-7">
                <span
                    v-for="(name, index) in weekdays"
                    :key="name"
                    role="columnheader"
                    class="flex h-7.75 items-center justify-center text-12"
                    :class="index >= 5 ? 'text-ink-4' : 'text-ink-3'"
                >{{ name }}</span>
            </div>
            <div v-for="(week, index) in weeks" :key="index" role="row" class="grid grid-cols-7" :class="compact ? 'h-8.5' : 'h-11'">
                <span v-for="(date, day) in week" :key="date ?? `blank-${day}`" role="gridcell" class="flex justify-center" :class="date ? bandClass(date) : ''">
                    <button
                        v-if="date"
                        type="button"
                        :data-date="date"
                        :tabindex="date === focused ? 0 : -1"
                        :disabled="!allowed(date)"
                        :aria-label="`${formatDayMonthWords(date)} ${dateParts(date).year}`"
                        :aria-pressed="isEnd(date) ? 'true' : 'false'"
                        :aria-current="date === today ? 'date' : undefined"
                        class="num flex items-center justify-center rounded-full disabled:cursor-not-allowed"
                        :class="[compact ? 'size-8.5 text-13' : 'size-11 text-15', dayClass(date)]"
                        @click="pick(date)"
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
                class="flex items-center justify-center rounded-control text-15"
                :class="monthClass(item.month)"
                @click="chooseMonth(item.month)"
            >
                {{ item.name }}
            </button>
        </div>
    </div>
</template>
