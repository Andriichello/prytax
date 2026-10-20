<script setup lang="ts">
import { computed } from 'vue';
import type { QuarterLiability, TaxLiability } from '@/api/types';
import { quarterLabel } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney } from '@/utils/money';

/**
 * The dark summary of a quarter (handoff §7): its income and the year's, ЄП and ВЗ owed by the cumulative method with
 * two kopeck cross-checks each (a non-zero Δ in amber), ЄСВ, and the year's totals and income limit.
 */
const { summary } = defineProps<{ summary: QuarterLiability }>();

const previous = computed(() => (summary.quarter > 1 ? `за ${quarterLabel(summary.quarter - 1)}` : 'на початок року'));

const taxes = computed(() => [
    { name: 'ЄП', liability: summary.single_tax },
    { name: 'ВЗ', liability: summary.military_levy },
]);

const ratePercent = (liability: TaxLiability): string => `${Number((Number(liability.rate) * 100).toFixed(2))} %`;
const deltaClass = (delta: string): string => (compareAmounts(delta, '0') === 0 ? '' : 'text-warning-on-ink');
</script>

<template>
    <section aria-label="Підсумок кварталу" class="mt-1.5 flex flex-col gap-4 rounded-card bg-ink px-4.5 py-5 text-on-ink">
        <div class="flex flex-col gap-1">
            <span class="text-14 text-on-ink-2">Дохід за {{ quarterLabel(summary.quarter) }} {{ summary.year }}</span>
            <span class="num text-30 font-semibold tracking-[-0.03em]">{{ formatMoney(summary.quarter_income, 'UAH') }}</span>
        </div>
        <div class="flex flex-col gap-1 border-t border-divider-ink pt-4">
            <span class="text-14 text-on-ink-2">Дохід з початку року</span>
            <span class="num text-22 font-semibold tracking-[-0.02em]">{{ formatMoney(summary.ytd_income, 'UAH') }}</span>
        </div>

        <div v-for="tax in taxes" :key="tax.name" class="flex flex-col gap-2.5 border-t border-divider-ink pt-4">
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-16 font-semibold">{{ tax.name }} до сплати</span>
                <span class="num text-18 font-semibold">{{ formatAmount(tax.liability.owed) }}</span>
            </div>
            <span class="num text-12 text-on-ink-2 whitespace-normal">
                {{ formatAmount(tax.liability.ytd) }} за рік − {{ formatAmount(tax.liability.previous_ytd) }} {{ previous }}
            </span>
            <div class="flex flex-col gap-1 pt-1">
                <span class="text-13 text-on-ink-2">Перевірка: {{ ratePercent(tax.liability) }} від доходу кварталу</span>
                <span class="num self-end text-14">
                    {{ formatAmount(tax.liability.standalone) }}
                    <span :class="deltaClass(tax.liability.standalone_delta)">Δ {{ formatAmount(tax.liability.standalone_delta) }}</span>
                </span>
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-13 text-on-ink-2">Перевірка: сума по надходженнях</span>
                <span class="num self-end text-14">
                    {{ formatAmount(tax.liability.hints_sum) }}
                    <span :class="deltaClass(tax.liability.hints_delta)">Δ {{ formatAmount(tax.liability.hints_delta) }}</span>
                </span>
            </div>
        </div>

        <div class="flex items-baseline justify-between gap-3 border-t border-divider-ink pt-4">
            <span class="flex flex-col gap-1">
                <span class="text-16 font-semibold">ЄСВ</span>
                <span class="num text-12 text-on-ink-2">{{ summary.esv.months }} × {{ formatAmount(summary.esv.monthly) }}</span>
            </span>
            <span class="num text-18 font-semibold">{{ formatAmount(summary.esv.owed) }}</span>
        </div>

        <div class="flex flex-col divide-y divide-divider-ink border-t border-divider-ink">
            <div class="flex items-baseline justify-between gap-3 py-3.5">
                <span class="text-14 text-on-ink-2">ЄП за рік</span>
                <span class="num text-15 font-semibold">{{ formatAmount(summary.single_tax.ytd) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-3 py-3.5">
                <span class="text-14 text-on-ink-2">ВЗ за рік</span>
                <span class="num text-15 font-semibold">{{ formatAmount(summary.military_levy.ytd) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-3 py-3.5">
                <span class="text-14 text-on-ink-2">ЄСВ за рік</span>
                <span class="num text-15 font-semibold">{{ formatAmount(summary.esv.ytd) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-3 pt-3.5">
                <span class="text-14 text-on-ink-2">Ліміт доходу</span>
                <span class="num text-15 font-semibold" :class="Number(summary.cap.percent) >= 80 ? 'text-warning-on-ink' : ''">{{ summary.cap.percent }} %</span>
            </div>
            <p v-if="compareAmounts(summary.cap.excess, '0') > 0" class="m-0 pt-3.5 text-13 leading-normal text-warning-on-ink">
                Перевищення ліміту <span class="num">{{ formatMoney(summary.cap.excess, 'UAH') }}</span> оподатковується за
                {{ Number((Number(summary.cap.excess_rate) * 100).toFixed(2)) }} %: <span class="num">{{ formatMoney(summary.cap.excess_tax, 'UAH') }}</span>.
            </p>
        </div>
    </section>
</template>
