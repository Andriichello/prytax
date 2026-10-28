<script setup lang="ts">
import { computed } from 'vue';
import type { TaxesBookYear } from '@/api/types';
import { t } from '@/i18n';
import { quarterLabel } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * A cumulative period of the books' Податки (M-Books-Taxes, design.md §16.7): «I–IV кв. 2026» (with «поточний») and
 * what it accrues; then ЄСВ, ЄП and ВЗ with the last quarter's own amount, the cumulative one and what is paid of it,
 * green once all of it is, with a sent amount in amber under it. A quarter, whose ЄП and ВЗ are none yet, says when
 * they come.
 */
const { period, year } = defineProps<{
    period: TaxesBookYear['cumulative'][number];
    year: number;
}>();

const marks = (): string[] => [t('accounting.books.quarterMarks.q1'), t('accounting.books.quarterMarks.q2'), t('accounting.books.quarterMarks.q3'), t('accounting.books.quarterMarks.q4')];

const title = computed(() => (period.to_quarter === 1
    ? `${quarterLabel(1)} ${year}`
    : t('accounting.books.periodRange', { first: marks()[0], to: marks()[period.to_quarter - 1], year })));

const above = (amount: string): boolean => compareAmounts(amount, '0') > 0;

const noIncomeTaxes = computed(() => period.rows
    .filter((row) => row.tax_type !== 'social_contribution')
    .every((row) => !above(row.quarter)));

const row = 'grid grid-cols-[40px_repeat(3,minmax(0,1fr))] items-baseline gap-1.5 px-3.5';
</script>

<template>
    <section class="flex flex-col overflow-hidden rounded-card border border-line bg-surface pb-1">
        <div class="flex items-center justify-between gap-2 px-3.5 pt-3">
            <span class="flex items-center gap-2">
                <h3 class="m-0 text-16 font-semibold">{{ title }}</h3>
                <span v-if="period.current" class="rounded-[6px] bg-accent-soft px-1.5 py-0.5 text-11 font-semibold text-accent-hover">{{ t('accounting.books.current') }}</span>
            </span>
            <span class="num text-13 text-ink-2">{{ formatMoney(period.total, 'UAH') }}</span>
        </div>
        <div :class="row" class="pt-2 pb-1.5 text-11 text-ink-3">
            <span />
            <span class="text-right">{{ t('accounting.books.forQuarter', { quarter: quarterLabel(period.to_quarter) }) }}</span>
            <span class="text-right">{{ t('accounting.books.cumulative') }}</span>
            <span class="text-right">{{ t('accounting.taxes.paid') }}</span>
        </div>
        <div v-for="tax in period.rows" :key="tax.tax_type" :class="row" class="border-t border-divider py-2">
            <span class="text-14 font-semibold">{{ TAX_TYPES[tax.tax_type] }}</span>
            <span class="num text-right text-13" :class="above(tax.quarter) ? '' : 'text-ink-4'">{{ formatAmount(tax.quarter) }}</span>
            <span class="num text-right text-13 font-semibold" :class="above(tax.cumulative) ? '' : 'text-ink-4'">{{ formatAmount(tax.cumulative) }}</span>
            <span class="text-right">
                <span
                    class="num text-13"
                    :class="above(tax.paid) && compareAmounts(tax.paid, tax.cumulative) >= 0 ? 'text-positive' : above(tax.cumulative) ? 'text-ink' : 'text-ink-4'"
                >{{ formatAmount(tax.paid) }}</span>
                <span v-if="above(tax.sent)" class="num block text-11 text-warning">+{{ formatAmount(tax.sent) }}</span>
            </span>
        </div>
        <p v-if="noIncomeTaxes" class="m-0 border-t border-divider px-3.5 pt-2 pb-1.5 text-12 text-ink-3">
            {{ t('accounting.books.taxesHint', { quarter: quarterLabel(period.to_quarter) }) }}
        </p>
    </section>
</template>
