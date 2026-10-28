<script setup lang="ts">
import { ref, watch } from 'vue';
import type { IncomeBookYear } from '@/api/types';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';
import { formatDayMonth, monthName, quarterLabel } from '@/utils/dates';
import { formatAmount } from '@/utils/money';

/**
 * The table of Доходи (M-Books-Income, M-BooksStates; design.md §16.7): the started quarters newest first, each a
 * black row with its total and the running total at its end; under it its months with their receipts counted, which
 * open to the receipts (the current month is open), each with the running total after it. A past month without
 * income reads «без доходу», the current one «ще немає». `wide` adds the invoice number after the client (desktop).
 */
const { book, wide = false } = defineProps<{
    book: IncomeBookYear;
    wide?: boolean;
}>();

const open = ref(new Set<number>());

/* A new year opens its current month */
watch(() => book, (shown) => {
    open.value = new Set(shown.quarters.flatMap((quarter) => quarter.months.filter((month) => month.current).map((month) => month.month)));
}, { immediate: true });

function toggle(month: number): void {
    const next = new Set(open.value);

    if (next.has(month)) {
        next.delete(month);
    } else {
        next.add(month);
    }

    open.value = next;
}

const row = 'grid grid-cols-[minmax(0,1fr)_96px_104px] items-center gap-1 px-3';
</script>

<template>
    <section class="flex flex-col overflow-hidden rounded-card border border-line bg-surface">
        <div :class="row" class="border-b border-line py-2.5 text-12 text-ink-3">
            <span class="invisible">{{ t('accounting.books.month') }}</span>
            <span class="text-right">{{ t('accounting.books.incomeUah') }}</span>
            <span class="text-right">{{ t('accounting.books.sinceStart') }}</span>
        </div>
        <template v-for="quarter in book.quarters" :key="quarter.quarter">
            <div :class="row" class="bg-ink py-3 text-white">
                <span class="flex flex-col">
                    <span class="text-15 font-semibold">{{ quarterLabel(quarter.quarter) }}</span>
                    <span class="text-11 text-on-ink-3">{{ quarter.current ? t('accounting.books.totalCurrent') : t('accounting.books.total') }}</span>
                </span>
                <span class="num text-right text-13 font-bold">{{ formatAmount(quarter.total) }}</span>
                <span class="num text-right text-13 font-bold">{{ formatAmount(quarter.ytd_end) }}</span>
            </div>
            <template v-for="month in quarter.months" :key="month.month">
                <button
                    v-if="month.state === 'ok'"
                    type="button"
                    :aria-expanded="open.has(month.month) ? 'true' : 'false'"
                    :class="row"
                    class="w-full border-t border-divider bg-surface py-3 text-left text-ink"
                    @click="toggle(month.month)"
                >
                    <span class="flex min-w-0 items-center gap-1">
                        <Icon name="chevronRight" :size="16" class="shrink-0 text-ink-3 transition-transform" :class="open.has(month.month) ? 'rotate-90' : ''" />
                        <span class="truncate text-15">{{ monthName(month.month) }}</span>
                        <span class="num shrink-0 text-11 text-ink-3">· {{ month.count }}</span>
                    </span>
                    <span class="num text-right text-13">{{ formatAmount(month.total) }}</span>
                    <span class="num text-right text-13 text-ink-2">{{ formatAmount(month.ytd_end) }}</span>
                </button>
                <div v-else :class="row" class="border-t border-divider bg-surface py-3 text-ink">
                    <span class="flex min-w-0 items-baseline gap-1.5 pl-5">
                        <span class="truncate text-15">{{ monthName(month.month) }}</span>
                        <span class="text-12 text-ink-3">{{ month.state === 'not_yet' ? t('accounting.books.notYet') : t('accounting.books.noIncome') }}</span>
                    </span>
                    <span class="num text-right text-13 text-ink-3">—</span>
                    <span class="num text-right text-13 text-ink-2">{{ formatAmount(month.ytd_end) }}</span>
                </div>
                <template v-if="month.state === 'ok' && open.has(month.month)">
                    <RouterLink
                        v-for="entry in month.receipts"
                        :key="`${entry.kind}-${entry.id}`"
                        :to="{ name: 'payment', params: { id: entry.payment_id } }"
                        :class="row"
                        class="border-t border-divider bg-paper-light py-2 pl-8! text-ink no-underline hover:text-ink"
                    >
                        <span class="flex min-w-0 flex-col gap-px">
                            <span class="num text-13 font-semibold">{{ formatDayMonth(entry.credited_on) }}</span>
                            <span class="truncate text-11 text-ink-3">
                                {{ entry.client }}<template v-if="wide && entry.invoice_number"> · <span class="num">{{ entry.invoice_number }}</span></template><template v-if="entry.kind === 'refund'"> · {{ t('accounting.books.refund') }}</template>
                            </span>
                        </span>
                        <span class="num text-right text-13" :class="entry.kind === 'refund' ? 'text-negative' : ''">{{ formatAmount(entry.amount_uah) }}</span>
                        <span class="num text-right text-12 text-ink-3">{{ formatAmount(entry.ytd_after) }}</span>
                    </RouterLink>
                </template>
            </template>
        </template>
    </section>
</template>
