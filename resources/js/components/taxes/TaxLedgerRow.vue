<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import type { TaxLedgerItem } from '@/api/types';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import TaxPaymentBadge from '@/components/taxes/TaxPaymentBadge.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { t } from '@/i18n';
import { formatDate, formatDayMonthWords, monthNameInText, quarterLabel } from '@/utils/dates';
import { formatAmount } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * A row of Податки's ledger (design.md §16.5): the tax (a 44 px label), the amount (struck through when it failed)
 * and the status; then what it is for (the receipt's invoice and client, or ЄСВ's month); then, owed, when the money
 * came and the deadline, or, paid, the date and the account. It opens the payment: a payment's own form, or, owed, a
 * new one for the receipt's tax or for ЄСВ's month, for a user who can record it (`canPay`). On desktop (D-Taxes,
 * §17.4) it is a row: tax · the amount with the status under it (as Облік's ЄП and ВЗ) · what it is for · when, or,
 * under 960 px of the month's width (a container query), with «when» under «what it is for».
 */
const { item, canPay } = defineProps<{
    item: TaxLedgerItem;
    canPay: boolean;
}>();

const to = computed<RouteLocationRaw | null>(() => {
    const esv = item.tax_type === 'social_contribution';

    if (item.tax_payment_id !== null) {
        if (esv) {
            return item.period_month ? { name: 'esv-payment', params: { id: item.tax_payment_id } } : null;
        }

        return item.receipt ? { name: 'tax-payment', params: { id: item.tax_payment_id } } : null;
    }

    if (!canPay) {
        return null;
    }

    return esv
        ? { name: 'esv-payment-new', query: { year: item.quarter.year, month: item.period_month ?? undefined } }
        : { name: 'tax-payment-new', query: { payment: item.receipt?.id, tax: item.tax_type } };
});

/** «12 жовтня · ПриватБанк ФОП · UAH», from a currency account «Monobank ФОП · USD → UAH». */
const paidLine = computed(() => {
    const account = item.account;
    const date = item.paid_at ? formatDayMonthWords(item.paid_at) : '—';

    if (!account) {
        return date;
    }

    return `${date} · ${account.name} · ${account.currency === 'UAH' ? 'UAH' : `${account.currency} → UAH`}`;
});

const dueLine = computed(() => t('accounting.taxPayment.payBy', { date: formatDate(item.due_at) }));
/** «When» of a desktop row: paid, the date and the account; owed, when the money came and the deadline. */
const whenLine = computed(() => {
    if (item.kind === 'payment') {
        return paidLine.value;
    }

    return item.receipt ? `${t('accounting.taxes.receivedOn', { date: formatDayMonthWords(item.receipt.received_at) })} · ${dueLine.value}` : dueLine.value;
});

const { isDesktop } = useBreakpoint();
</script>

<template>
    <component
        :is="to ? RouterLink : 'div'"
        v-if="isDesktop"
        :to="to ?? undefined"
        class="grid grid-cols-[48px_130px_minmax(0,1fr)] items-center gap-4 border-t border-divider px-5 py-3 text-ink no-underline hover:bg-paper-light hover:text-ink @min-[960px]:grid-cols-[48px_130px_minmax(0,1.2fr)_minmax(0,1fr)]"
    >
        <span class="text-13 font-semibold text-ink-2">{{ TAX_TYPES[item.tax_type] }}</span>
        <span class="flex flex-col items-end gap-1">
            <span class="num text-15 font-bold" :class="item.status === 'failed' ? 'text-tick line-through' : ''">{{ formatAmount(item.amount) }}</span>
            <TaxPaymentBadge :status="item.status === 'unpaid' ? null : item.status" short />
        </span>
        <span class="flex min-w-0 flex-col gap-0.5">
            <span class="flex min-w-0 items-center gap-1.5 text-13 text-ink-2">
                <template v-if="item.tax_type === 'social_contribution' || !item.receipt">
                    {{ t('accounting.taxes.forMonth', { month: item.period_month ? monthNameInText(item.period_month) : quarterLabel(item.quarter.quarter) }) }}
                </template>
                <template v-else>
                    <VirtualMarker v-if="item.receipt.invoice_source === 'virtual'" size="sm" />
                    <span v-else-if="item.receipt.invoice_number" class="num text-ink">{{ item.receipt.invoice_number }}</span>
                    <span class="truncate">
                        <template v-if="item.receipt.invoice_number || item.receipt.invoice_source === 'virtual'">· </template>{{ item.receipt.client_name ?? item.receipt.description }}
                    </span>
                </template>
            </span>
            <span class="truncate text-12 text-ink-3 @min-[960px]:hidden">{{ whenLine }}</span>
        </span>
        <span class="hidden truncate text-12 text-ink-3 @min-[960px]:block">{{ whenLine }}</span>
    </component>
    <component :is="to ? RouterLink : 'div'" v-else :to="to ?? undefined" class="flex flex-col gap-1.5 border-t border-divider px-4 py-3 text-ink no-underline hover:text-ink">
        <span class="flex items-center gap-2.5">
            <span class="w-11 shrink-0 text-13 font-semibold whitespace-nowrap text-ink-2">{{ TAX_TYPES[item.tax_type] }}</span>
            <span class="num flex-1 text-16 font-bold" :class="item.status === 'failed' ? 'text-tick line-through' : ''">{{ formatAmount(item.amount) }}</span>
            <TaxPaymentBadge :status="item.status === 'unpaid' ? null : item.status" short />
        </span>
        <span class="flex gap-2.5">
            <span class="w-11 shrink-0" />
            <span class="flex min-w-0 flex-col gap-0.5">
                <span v-if="item.tax_type === 'social_contribution'" class="text-13 text-ink-2">
                    {{ t('accounting.taxes.forMonth', { month: item.period_month ? monthNameInText(item.period_month) : quarterLabel(item.quarter.quarter) }) }}
                </span>
                <span v-else-if="item.receipt" class="flex min-w-0 items-center gap-1.5 text-13 text-ink-2">
                    <VirtualMarker v-if="item.receipt.invoice_source === 'virtual'" size="sm" />
                    <span v-else-if="item.receipt.invoice_number" class="num text-ink">{{ item.receipt.invoice_number }}</span>
                    <span class="truncate">
                        <template v-if="item.receipt.invoice_number || item.receipt.invoice_source === 'virtual'">· </template>{{ item.receipt.client_name ?? item.receipt.description }}
                    </span>
                </span>
                <span v-else class="text-13 text-ink-2">{{ t('accounting.taxes.forMonth', { month: quarterLabel(item.quarter.quarter) }) }}</span>
                <span v-if="item.kind === 'payment'" class="truncate text-12 text-ink-3">{{ paidLine }}</span>
                <span v-else-if="item.receipt" class="text-12 text-ink-3">
                    {{ t('accounting.taxes.receivedOn', { date: formatDayMonthWords(item.receipt.received_at) }) }} · {{ dueLine }}
                </span>
                <span v-else class="text-12 text-ink-3">{{ dueLine }}</span>
            </span>
        </span>
    </component>
</template>
