<script setup lang="ts">
import { computed } from 'vue';
import type { AccountingRow } from '@/api/types';
import { formatDayMonthWords } from '@/utils/dates';
import { formatAmount, formatCompact, formatMoney, formatRate } from '@/utils/money';

/**
 * A receipt (or refund) in Облік (handoff §7): the invoice number and the original amount, the client and the NBU
 * rate, the date and the hryvnias; then, by the switches, ЄП and ВЗ, and the fees with what is left net. Grouped by
 * invoice, the row starts with the date and names the account instead.
 */
const { row, showTaxes, showFees, byInvoice = false } = defineProps<{
    row: AccountingRow;
    showTaxes: boolean;
    showFees: boolean;
    byInvoice?: boolean;
}>();

const isRefund = computed(() => row.type === 'refund');
const title = computed(() => row.invoice_number ?? row.description ?? (row.kind === 'advance' ? 'Аванс' : 'Інший дохід'));
</script>

<template>
    <RouterLink
        :to="{ name: 'payment', params: { id: row.payment_id } }"
        class="flex flex-col gap-1.5 px-4 pt-3 pb-2.5 text-ink no-underline hover:text-ink"
    >
        <span class="flex items-center justify-between gap-3">
            <span v-if="byInvoice" class="text-15 font-semibold">{{ formatDayMonthWords(row.date) }}</span>
            <span v-else class="truncate text-15 font-semibold" :class="row.invoice_number ? 'num' : ''">
                <template v-if="isRefund">Повернення · </template>{{ title }}
            </span>
            <span
                class="num shrink-0 rounded-badge px-2 py-0.5 text-15 font-semibold"
                :class="isRefund ? 'bg-negative-soft text-negative' : 'bg-positive-soft text-positive'"
            >
                {{ formatMoney(row.amount, row.currency) }}
            </span>
        </span>
        <span class="flex items-baseline justify-between gap-3">
            <span class="truncate text-13 text-ink-2">{{ byInvoice ? `на ${row.account_name ?? ''}` : row.client_name ?? row.description }}</span>
            <span v-if="row.currency !== 'UAH'" class="num text-12 text-ink-3">{{ formatRate(row.nbu_rate, row.currency) }}</span>
        </span>
        <span class="flex items-baseline justify-between gap-3">
            <span class="text-13 text-ink-2">{{ byInvoice ? '' : formatDayMonthWords(row.date) }}</span>
            <span class="num text-17 font-bold">{{ formatMoney(row.uah_amount, 'UAH') }}</span>
        </span>

        <span v-if="showTaxes || (showFees && row.type === 'payment')" class="mt-1 flex flex-col">
            <template v-if="showTaxes">
                <span class="flex items-baseline justify-between border-t border-dashed border-line-input py-2.25">
                    <span class="text-14 text-ink-2">ЄП</span>
                    <span class="num text-15 font-semibold">{{ formatAmount(row.single_tax_uah) }}</span>
                </span>
                <span class="flex items-baseline justify-between border-t border-divider py-2.25">
                    <span class="text-14 text-ink-2">ВЗ</span>
                    <span class="num text-15 font-semibold">{{ formatAmount(row.military_levy_uah) }}</span>
                </span>
            </template>
            <template v-if="showFees && row.type === 'payment'">
                <span class="flex items-baseline justify-between py-2.25" :class="showTaxes ? 'border-t border-divider' : 'border-t border-dashed border-line-input'">
                    <span class="text-14 text-ink-2">Комісії</span>
                    <span class="num text-15 font-semibold">{{ row.fees !== null ? formatCompact(row.fees, row.currency) : '—' }}</span>
                </span>
                <span class="flex items-baseline justify-between border-t border-divider py-2.25">
                    <span class="text-14 text-ink-2">Чисто</span>
                    <span class="num text-15 font-semibold text-positive">{{ row.net !== null ? formatCompact(row.net, row.currency) : '—' }}</span>
                </span>
            </template>
        </span>
    </RouterLink>
</template>
