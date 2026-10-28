<script setup lang="ts">
import { computed } from 'vue';
import type { OverviewCard, TaxType } from '@/api/types';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';
import { quarterLabel } from '@/utils/dates';
import { formatAmount, formatMoney } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * «Підсумок кварталу» of Облік (design.md §7, §16.5): the black band of the year's income limit (the share used, the
 * income so far and the limit, with ticks at 80 and 90 %), the quarter's income per currency (received of
 * invoiced), the income for taxes, and the quarter's ЄСВ, ЄП and ВЗ with what is paid. On desktop (`row`) they are
 * three cards side by side.
 */
const { card, year, quarter, row = false } = defineProps<{
    card: OverviewCard;
    year: number;
    quarter: number;
    /** The desktop arrangement: three cards in a row (D-Accounting). */
    row?: boolean;
}>();

const TAXES: TaxType[] = ['social_contribution', 'single_tax', 'military_levy'];

const capPercent = computed(() => Number(card.cap.percent));

/** The limit is a whole number of hryvnias: "10 091 049 ₴". */
const cap = computed(() => formatMoney(card.cap.income_cap.replace(/\.00$/, ''), 'UAH'));
</script>

<template>
    <article :class="row ? 'grid grid-cols-3 items-stretch gap-4' : 'flex flex-col overflow-hidden rounded-card border border-line bg-surface'">
        <div class="flex flex-col gap-2.5 bg-ink px-4 py-3.5 text-white" :class="row ? 'justify-between rounded-card' : ''">
            <div class="flex items-baseline justify-between">
                <span class="text-13 font-semibold tracking-[0.04em] text-on-ink-2 uppercase">{{ t('shell.overview.incomeCapFor', { year }) }}</span>
                <span class="num text-16 font-semibold" :class="capPercent >= 80 ? 'text-warning-on-ink' : ''">{{ card.cap.percent }} %</span>
            </div>
            <div class="relative flex h-2.5 overflow-hidden rounded-full bg-white/15" role="progressbar" :aria-valuenow="Math.round(capPercent)" aria-valuemin="0" aria-valuemax="100">
                <span class="rounded-full" :class="capPercent >= 80 ? 'bg-warning-bar' : 'bg-white'" :style="{ width: `${Math.min(100, capPercent)}%` }" />
                <span class="absolute top-0 left-[80%] h-full w-0.5 bg-white/40" aria-hidden="true" />
                <span class="absolute top-0 left-[90%] h-full w-0.5 bg-white/40" aria-hidden="true" />
            </div>
            <div class="flex justify-between text-13">
                <span class="num">{{ formatMoney(card.cap.ytd_income, 'UAH') }}</span>
                <span class="num text-on-ink-2">{{ t('shell.overview.ofTotal', { total: cap }) }}</span>
            </div>
        </div>

        <div class="flex flex-col gap-3 px-4 py-3.5" :class="row ? 'rounded-card border border-line bg-surface' : ''">
            <span class="text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">{{ t('shell.overview.incomeIn', { quarter: quarterLabel(quarter) }) }}</span>
            <div v-for="income in card.income" :key="income.currency" class="flex flex-col gap-1.5">
                <div class="flex items-baseline justify-between text-15 font-semibold">
                    <span>{{ income.currency }}</span>
                    <span class="num">{{ income.percent }} %</span>
                </div>
                <ProgressBar :percent="Number(income.percent)" />
                <span class="text-13 text-ink-2">
                    <Translated :text="t('shell.overview.receivedOf')">
                        <template #received><span class="num font-semibold text-ink">{{ formatAmount(income.received) }}</span></template>
                        <template #invoiced><span class="num">{{ formatAmount(income.invoiced) }}</span></template>
                    </Translated>
                </span>
            </div>
            <span v-if="card.income.length === 0" class="text-14 text-ink-3">{{ t('shell.overview.noInvoicesQuarter') }}</span>
            <span class="text-13 text-ink-2">
                <Translated :text="t('shell.overview.taxableIncome')">
                    <template #amount><span class="num">{{ formatMoney(card.taxable_income, 'UAH') }}</span></template>
                </Translated>
            </span>
        </div>

        <div class="flex flex-col gap-2.5 py-3.5" :class="row ? 'rounded-card border border-line bg-surface px-4' : 'mx-4 border-t border-divider'">
            <span class="text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">{{ t('shell.overview.taxesIn', { quarter: quarterLabel(quarter) }) }}</span>
            <div class="grid grid-cols-3 gap-2">
                <div v-for="tax in TAXES" :key="tax" class="flex flex-col gap-[3px]">
                    <span class="text-13 text-ink-3">{{ TAX_TYPES[tax] }}</span>
                    <span class="num text-16 font-semibold">{{ formatAmount(card.taxes[tax].owed) }}</span>
                    <span class="text-12 text-ink-3">
                        <Translated :text="t('shell.overview.paidAmount')">
                            <template #amount><span class="num">{{ formatAmount(card.taxes[tax].paid) }}</span></template>
                        </Translated>
                    </span>
                    <span v-if="Number(card.taxes[tax].pending) > 0" class="text-12 text-warning-text">
                        <Translated :text="t('shell.overview.sentAmount')">
                            <template #amount><span class="num">{{ formatAmount(card.taxes[tax].pending) }}</span></template>
                        </Translated>
                    </span>
                </div>
            </div>
        </div>
    </article>
</template>
