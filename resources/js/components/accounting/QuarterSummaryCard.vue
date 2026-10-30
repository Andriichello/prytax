<script setup lang="ts">
import type { OverviewCard, TaxType } from '@/api/types';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';
import { quarterLabel } from '@/utils/dates';
import { formatAmount, formatMoney } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * «Підсумок кварталу» of Облік (design.md §7, §16.5): the quarter's income per currency (received of invoiced), the
 * income for taxes, and the quarter's ЄСВ, ЄП and ВЗ with what is paid; the year's income limit is Книги's, not
 * here (the owner, 2026-10-30). On desktop (`cards`) they are two cards, one under the other, each the page's whole
 * width (the board drew them in a row).
 */
const { card, quarter, cards = false } = defineProps<{
    card: OverviewCard;
    quarter: number;
    /** The desktop arrangement: two cards, one under the other (D-Accounting). */
    cards?: boolean;
}>();

const TAXES: TaxType[] = ['social_contribution', 'single_tax', 'military_levy'];
</script>

<template>
    <article :class="cards ? '' : 'flex flex-col overflow-hidden rounded-card border border-line bg-surface'">
        <div :class="cards ? 'flex flex-col gap-4' : 'contents'">
            <div class="flex flex-col gap-3 px-4 py-3.5" :class="cards ? 'rounded-card border border-line bg-surface' : ''">
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

            <div class="flex flex-col gap-2.5 py-3.5" :class="cards ? 'rounded-card border border-line bg-surface px-4' : 'mx-4 border-t border-divider'">
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
        </div>
    </article>
</template>
