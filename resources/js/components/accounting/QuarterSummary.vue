<script setup lang="ts">
import { computed } from 'vue';
import type { QuarterLiability, TaxLiability } from '@/api/types';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';
import { quarterLabel } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney } from '@/utils/money';

/**
 * The dark summary of a quarter (handoff §7): its income and the year's, ЄП and ВЗ owed by the cumulative method with
 * two kopeck cross-checks each (a non-zero Δ in amber), ЄСВ, and the year's totals and income limit.
 */
const { summary } = defineProps<{ summary: QuarterLiability }>();

/** "5 000.00 за рік − 3 000.00 за II кв.": the year's tax so far less the one of the previous quarter. */
function ytdLine(liability: TaxLiability): string {
    const params = { ytd: formatAmount(liability.ytd), previousYtd: formatAmount(liability.previous_ytd) };

    return summary.quarter > 1
        ? t('accounting.summary.ytdMinusPrevious', { ...params, quarter: quarterLabel(summary.quarter - 1) })
        : t('accounting.summary.ytdMinusYearStart', params);
}

const percent = (rate: string): string => `${Number((Number(rate) * 100).toFixed(2))} %`;
const isAboveCap = computed(() => compareAmounts(summary.cap.excess, '0') > 0);

const taxes = computed(() => [
    { key: 'single_tax', name: t('core.taxes.singleTax'), liability: summary.single_tax, aboveCap: isAboveCap.value },
    { key: 'military_levy', name: t('core.taxes.militaryLevy'), liability: summary.military_levy, aboveCap: false },
]);

/** "5 %"; ЄП above the income cap is taxed at the excess rate on the excess, so the check names both rates. */
const rateOf = (liability: TaxLiability, aboveCap: boolean): string => (aboveCap
    ? t('accounting.summary.rateAboveCap', { rate: percent(liability.rate), excessRate: percent(summary.cap.excess_rate) })
    : percent(liability.rate));
const deltaClass = (delta: string): string => (compareAmounts(delta, '0') === 0 ? '' : 'text-warning-on-ink');
</script>

<template>
    <section :aria-label="t('accounting.summary.quarterSummary')" class="mt-1.5 flex flex-col gap-4 rounded-card bg-ink px-4.5 py-5 text-on-ink">
        <div class="flex flex-col gap-1">
            <span class="text-14 text-on-ink-2">{{ t('accounting.summary.incomeFor', { quarter: quarterLabel(summary.quarter), year: summary.year }) }}</span>
            <span class="num text-30 font-semibold tracking-[-0.03em]">{{ formatMoney(summary.quarter_income, 'UAH') }}</span>
        </div>
        <div class="flex flex-col gap-1 border-t border-divider-ink pt-4">
            <span class="text-14 text-on-ink-2">{{ t('accounting.summary.incomeYearToDate') }}</span>
            <span class="num text-22 font-semibold tracking-[-0.02em]">{{ formatMoney(summary.ytd_income, 'UAH') }}</span>
        </div>

        <div v-for="tax in taxes" :key="tax.key" class="flex flex-col gap-2.5 border-t border-divider-ink pt-4">
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-16 font-semibold">{{ t('accounting.summary.taxOwed', { tax: tax.name }) }}</span>
                <span class="num text-18 font-semibold">{{ formatAmount(tax.liability.owed) }}</span>
            </div>
            <span class="num text-12 text-on-ink-2 whitespace-normal">
                {{ ytdLine(tax.liability) }}
            </span>
            <div class="flex flex-col gap-1 pt-1">
                <span class="text-13 text-on-ink-2">{{ t('accounting.summary.crossCheckRate', { rate: rateOf(tax.liability, tax.aboveCap) }) }}</span>
                <span class="num self-end text-14">
                    {{ formatAmount(tax.liability.standalone) }}
                    <span :class="deltaClass(tax.liability.standalone_delta)">Δ {{ formatAmount(tax.liability.standalone_delta) }}</span>
                </span>
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-13 text-on-ink-2">{{ t('accounting.summary.crossCheckSum') }}</span>
                <span class="num self-end text-14">
                    {{ formatAmount(tax.liability.hints_sum) }}
                    <span :class="deltaClass(tax.liability.hints_delta)">Δ {{ formatAmount(tax.liability.hints_delta) }}</span>
                </span>
            </div>
        </div>

        <div class="flex items-baseline justify-between gap-3 border-t border-divider-ink pt-4">
            <span class="flex flex-col gap-1">
                <span class="text-16 font-semibold">{{ t('core.taxes.socialContribution') }}</span>
                <span class="num text-12 text-on-ink-2">{{ summary.esv.months }} × {{ formatAmount(summary.esv.monthly) }}</span>
            </span>
            <span class="num text-18 font-semibold">{{ formatAmount(summary.esv.owed) }}</span>
        </div>

        <div class="flex flex-col divide-y divide-divider-ink border-t border-divider-ink">
            <div class="flex items-baseline justify-between gap-3 py-3.5">
                <span class="text-14 text-on-ink-2">{{ t('accounting.summary.singleTaxYtd') }}</span>
                <span class="num text-15 font-semibold">{{ formatAmount(summary.single_tax.ytd) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-3 py-3.5">
                <span class="text-14 text-on-ink-2">{{ t('accounting.summary.militaryLevyYtd') }}</span>
                <span class="num text-15 font-semibold">{{ formatAmount(summary.military_levy.ytd) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-3 py-3.5">
                <span class="text-14 text-on-ink-2">{{ t('accounting.summary.sscYtd') }}</span>
                <span class="num text-15 font-semibold">{{ formatAmount(summary.esv.ytd) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-3 pt-3.5">
                <span class="text-14 text-on-ink-2">{{ t('accounting.summary.incomeCap') }}</span>
                <span class="num text-15 font-semibold" :class="Number(summary.cap.percent) >= 80 ? 'text-warning-on-ink' : ''">{{ summary.cap.percent }} %</span>
            </div>
            <p v-if="isAboveCap" class="m-0 pt-3.5 text-13 leading-normal text-warning-on-ink">
                <Translated :text="t('accounting.summary.excessTaxed', { rate: percent(summary.cap.excess_rate) })">
                    <template #excess><span class="num">{{ formatMoney(summary.cap.excess, 'UAH') }}</span></template>
                    <template #tax><span class="num">{{ formatMoney(summary.cap.excess_tax, 'UAH') }}</span></template>
                </Translated>
            </p>
        </div>
    </section>
</template>
