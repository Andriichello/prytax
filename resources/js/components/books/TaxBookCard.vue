<script setup lang="ts">
import { computed } from 'vue';
import type { TaxesBookYear } from '@/api/types';
import TaxFigures from '@/components/taxes/TaxFigures.vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';
import { formatDate, monthNameInText, quarterLabel } from '@/utils/dates';
import { compareAmounts, formatAmount } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * A tax of the books' Податки grouped by tax (M-Books-TaxesByTax, design.md §16.7): its name and the nearest
 * deadline of what isn't paid («ЄСВ за жовтень — до …», «IV кв. — до …», or «✓ закрито»); a bar of segments, a month
 * each for ЄСВ and a quarter each for ЄП and ВЗ, each filled with what is paid (green) and sent (amber) of it; the
 * three columns; and its quarters, newest first, by themselves and cumulative.
 */
const { tax } = defineProps<{ tax: TaxesBookYear['by_tax'][number] }>();

const isEsv = computed(() => tax.tax_type === 'social_contribution');

const share = (part: string, whole: string): number => (compareAmounts(whole, '0') > 0 ? Math.min(100, (Number(part) / Number(whole)) * 100) : 0);

const due = computed(() => {
    if (!tax.due_at || tax.due_period === null) {
        return null;
    }

    return isEsv.value
        ? t('accounting.books.esvDue', { month: monthNameInText(tax.due_period), date: formatDate(tax.due_at) })
        : t('accounting.books.quarterDue', { quarter: quarterLabel(tax.due_period), date: formatDate(tax.due_at) });
});

const closed = computed(() => !due.value && compareAmounts(tax.accrued, '0') > 0);
</script>

<template>
    <section class="flex flex-col gap-3 rounded-card border border-line bg-surface px-4 py-3.5">
        <div class="flex items-baseline justify-between gap-3">
            <h3 class="m-0 text-17 font-semibold">{{ TAX_TYPES[tax.tax_type] }}</h3>
            <span v-if="due" class="text-12 text-ink-3">{{ due }}</span>
            <span v-else-if="closed" class="flex items-center gap-1 text-12 text-positive"><Icon name="check" :size="14" /> {{ t('accounting.taxes.closed') }}</span>
        </div>
        <div class="flex flex-col gap-1">
            <div class="flex gap-0.5" aria-hidden="true">
                <span v-for="segment in tax.segments" :key="segment.period" class="flex h-2 min-w-0 flex-1 overflow-hidden rounded-[2px] bg-line-input">
                    <span class="bg-positive" :style="{ width: `${share(segment.paid, segment.accrued)}%` }" />
                    <span class="bg-sent-bar" :style="{ width: `${share(segment.sent, segment.accrued)}%` }" />
                </span>
            </div>
            <span class="text-11 text-ink-3">{{ isEsv ? t('accounting.books.byMonths') : t('accounting.books.byQuarters') }}</span>
        </div>
        <TaxFigures :figures="tax" />
        <div v-if="tax.quarters.length" class="flex flex-col border-t border-divider pt-2">
            <div class="grid grid-cols-[minmax(0,1fr)_100px_108px] gap-2 pb-1 text-11 text-ink-3">
                <span />
                <span class="text-right">{{ t('accounting.books.forQuarterColumn') }}</span>
                <span class="text-right">{{ t('accounting.books.cumulative') }}</span>
            </div>
            <div v-for="quarter in tax.quarters" :key="quarter.quarter" class="grid grid-cols-[minmax(0,1fr)_100px_108px] gap-2 py-0.5 text-13">
                <span class="text-ink-2">{{ quarterLabel(quarter.quarter) }}</span>
                <span class="num text-right">{{ formatAmount(quarter.amount) }}</span>
                <span class="num text-right font-semibold">{{ formatAmount(quarter.cumulative) }}</span>
            </div>
        </div>
    </section>
</template>
