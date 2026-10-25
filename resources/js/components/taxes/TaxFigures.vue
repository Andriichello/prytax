<script setup lang="ts">
import type { TaxLedgerFigures } from '@/api/types';
import { t } from '@/i18n';
import { compareAmounts, formatAmount } from '@/utils/money';

/**
 * The three columns of Податки's summaries and month headers (design.md §16.5): Нараховано, Надіслано (amber above
 * zero) and Сплачено (green above zero). On ink (`dark`) the labels are lighter and the colours brighter.
 */
const { figures, dark = false } = defineProps<{
    figures: TaxLedgerFigures;
    dark?: boolean;
}>();

const above = (amount: string): boolean => compareAmounts(amount, '0') > 0;
</script>

<template>
    <div class="grid grid-cols-3 gap-2">
        <span class="flex flex-col gap-0.5">
            <span class="text-11" :class="dark ? 'text-on-ink-2' : 'text-ink-3'">{{ t('accounting.taxes.accrued') }}</span>
            <span class="num text-14 font-semibold" :class="dark ? 'text-white' : 'text-ink'">{{ formatAmount(figures.accrued) }}</span>
        </span>
        <span class="flex flex-col gap-0.5">
            <span class="text-11" :class="dark ? 'text-on-ink-2' : 'text-ink-3'">{{ t('accounting.taxes.sent') }}</span>
            <span
                class="num text-14 font-semibold"
                :class="above(figures.sent) ? (dark ? 'text-warning-on-ink' : 'text-warning') : (dark ? 'text-white' : 'text-ink')"
            >
                {{ formatAmount(figures.sent) }}
            </span>
        </span>
        <span class="flex flex-col gap-0.5">
            <span class="text-11" :class="dark ? 'text-on-ink-2' : 'text-ink-3'">{{ t('accounting.taxes.paid') }}</span>
            <span
                class="num text-14 font-semibold"
                :class="above(figures.paid) ? (dark ? 'text-positive-on-ink' : 'text-positive') : (dark ? 'text-white' : 'text-ink')"
            >
                {{ formatAmount(figures.paid) }}
            </span>
        </span>
    </div>
</template>
