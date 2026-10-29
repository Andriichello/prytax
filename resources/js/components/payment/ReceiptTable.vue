<script setup lang="ts">
import { RouterLink } from 'vue-router';
import type { Payment, TaxType } from '@/api/types';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import TaxPaymentBadge from '@/components/taxes/TaxPaymentBadge.vue';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { formatDayMonthWords } from '@/utils/dates';
import { formatAmount, formatCompact, formatMoney, multiplyDecimals } from '@/utils/money';
import { receiptTaxPayment } from '@/utils/taxes';

/**
 * Receipts as desktop Облік lists them (D-Accounting, design.md §17.4): an optional dark header (a month or an
 * invoice, its count and its total), then a table, whose columns keep their widths (owner #70–#72): Інвойс (the
 * number and the client) · Дата · Сума (on a green pill) · Курс НБУ · Дохід ₴ · ЄП 5 % · ВЗ 1 %. ЄП and ВЗ show the
 * amount with the status badge under it, so a long badge never meets Дохід; each opens its payment, with a hover of
 * its own. With `fees`, a second line of the receipt's row, under a dashed line, in the same grid: the net income
 * under the amount (in its currency) and under Дохід (in hryvnias, at the receipt's NBU rate, at which its taxes count
 * too; once for a hryvnia receipt), and the fees under ЄП. The row with its line is one block, which a solid divider
 * parts from the next, with one hover, and opens the receipt wherever it is clicked. The columns follow the table's
 * own width (a container query): under 920 px the date goes under the invoice, and the income in hryvnias and the NBU
 * rate under the amount (and the net in hryvnias under the net); under 560 px the columns narrow.
 */
const { receipts, title = undefined, count = undefined, total = undefined, taxes: showTaxes = true, feesOf = null } = defineProps<{
    receipts: Payment[];
    title?: string;
    count?: string;
    total?: string;
    taxes?: boolean;
    /** The fees and net income of a receipt, in its currency, when «Комісії й чисто» is on. */
    feesOf?: ((receipt: Payment) => { fees: string | null; net: string | null } | null) | null;
}>();

const { can } = useSession();

type ReceiptTax = Extract<TaxType, 'single_tax' | 'military_levy'>;

const TAXES: ReceiptTax[] = ['single_tax', 'military_levy'];
const columns = 'grid-cols-[minmax(0,1fr)_116px_88px_88px] gap-3 @min-[560px]:grid-cols-[minmax(0,1fr)_132px_116px_116px] @min-[560px]:gap-4 @min-[920px]:grid-cols-[minmax(0,1fr)_72px_124px_72px_124px_116px_116px]';

/** A receipt's net income in hryvnias: its own for a hryvnia receipt, else at its NBU rate; null when unknown. */
function netUah(receipt: Payment, net: string | null | undefined): string | null {
    if (net === null || net === undefined) {
        return null;
    }

    if (receipt.currency === 'UAH') {
        return net;
    }

    return receipt.nbu_rate ? multiplyDecimals(net, receipt.nbu_rate) : null;
}

function tax(receipt: Payment, type: ReceiptTax) {
    const taxPayment = receiptTaxPayment(receipt.taxPayments, type);

    return {
        amount: taxPayment?.amount ?? (type === 'single_tax' ? receipt.single_tax_uah : receipt.military_levy_uah),
        taxPayment,
        to: taxPayment
            ? { name: 'tax-payment', params: { id: taxPayment.id } }
            : can('create', 'tax-payments') ? { name: 'tax-payment-new', query: { payment: receipt.id, tax: type } } : null,
    };
}
</script>

<template>
    <section class="@container overflow-hidden rounded-card border border-line bg-surface" :aria-label="title">
        <div v-if="title" class="flex items-center justify-between bg-ink px-5 py-3.5 text-white">
            <span class="flex flex-col">
                <span class="text-17 font-semibold">{{ title }}</span>
                <span v-if="count" class="text-12 text-on-ink-3">{{ count }}</span>
            </span>
            <span v-if="total" class="num text-18 font-bold">{{ total }}</span>
        </div>
        <div class="grid border-b border-line px-5 py-2.5 text-12 text-ink-3" :class="columns">
            <span>{{ t('money.receiptTable.invoice') }}</span>
            <span class="hidden @min-[920px]:block">{{ t('core.common.date') }}</span>
            <span class="text-right">{{ t('invoices.table.amount') }}</span>
            <span class="hidden text-right @min-[920px]:block">{{ t('money.receiptTable.nbuRate') }}</span>
            <span class="hidden text-right @min-[920px]:block">{{ t('money.receiptTable.income') }}</span>
            <span class="text-right">{{ showTaxes ? t('money.receiptTable.singleTax') : '' }}</span>
            <span class="text-right">{{ showTaxes ? t('money.receiptTable.militaryLevy') : '' }}</span>
        </div>
        <div
            v-for="(receipt, index) in receipts"
            :key="receipt.id"
            class="relative hover:bg-paper-light"
            :class="index > 0 ? 'border-t border-divider' : ''"
        >
            <div class="grid items-center px-5 py-3.5" :class="columns">
                <RouterLink
                    :to="{ name: 'payment', params: { id: receipt.id } }"
                    class="flex min-w-0 flex-col gap-0.5 text-ink no-underline after:absolute after:inset-0 hover:text-ink"
                >
                    <VirtualMarker v-if="receipt.invoice?.source === 'virtual'" class="self-start" />
                    <span v-else class="num truncate text-14 font-semibold">{{ receipt.invoice?.number ?? '—' }}</span>
                    <span class="truncate text-13 text-ink-2">{{ receipt.invoice?.client?.name }}</span>
                    <span class="text-12 text-ink-3 @min-[920px]:hidden">{{ formatDayMonthWords(receipt.received_at) }}</span>
                </RouterLink>
                <span class="hidden text-13 text-ink-2 @min-[920px]:block">{{ formatDayMonthWords(receipt.received_at) }}</span>
                <span class="flex flex-col items-end gap-1">
                    <span class="num rounded-badge bg-positive-soft px-2 py-0.5 text-13 font-semibold whitespace-nowrap text-positive">{{ formatMoney(receipt.amount, receipt.currency) }}</span>
                    <template v-if="receipt.currency !== 'UAH'">
                        <span class="num text-14 font-bold whitespace-nowrap @min-[920px]:hidden">{{ formatMoney(receipt.uah_amount, 'UAH') }}</span>
                        <span class="num text-11 text-ink-3 @min-[920px]:hidden">{{ formatAmount(receipt.nbu_rate) }} ₴</span>
                    </template>
                </span>
                <span class="num hidden text-right text-12 text-ink-3 @min-[920px]:block">{{ receipt.currency !== 'UAH' ? `${formatAmount(receipt.nbu_rate)} ₴` : '—' }}</span>
                <span class="num hidden text-right text-15 font-bold @min-[920px]:block">{{ formatMoney(receipt.uah_amount, 'UAH') }}</span>
                <template v-if="showTaxes">
                    <component
                        :is="tax(receipt, type).to ? RouterLink : 'span'"
                        v-for="type in TAXES"
                        :key="type"
                        :to="tax(receipt, type).to ?? undefined"
                        class="relative z-[1] -mx-2 -my-1.5 flex flex-col items-end gap-1 rounded-lg px-2 py-1.5 text-ink no-underline hover:text-ink"
                        :class="tax(receipt, type).to ? 'hover:bg-sunk' : ''"
                    >
                        <span class="num text-14 font-semibold" :class="tax(receipt, type).taxPayment?.status === 'failed' ? 'text-ink-3 line-through' : ''">
                            {{ formatAmount(tax(receipt, type).amount) }}
                        </span>
                        <TaxPaymentBadge :status="tax(receipt, type).taxPayment?.status ?? null" :paid-at="tax(receipt, type).taxPayment?.paid_at" short />
                    </component>
                </template>
                <template v-else><span /><span /></template>
            </div>
            <div v-if="feesOf && feesOf(receipt)" class="grid items-center border-t border-dashed border-line-input px-5 py-2 text-13" :class="columns">
                <span />
                <span class="hidden @min-[920px]:block" />
                <span class="flex flex-col items-end gap-0.5">
                    <span v-if="receipt.currency !== 'UAH'" class="num font-semibold whitespace-nowrap text-positive">
                        <span class="font-sans text-12 font-normal text-ink-3">{{ t('money.receiptCard.netShort') }}</span>
                        {{ feesOf(receipt)?.net !== null ? formatCompact(feesOf(receipt)!.net!, receipt.currency) : '—' }}
                    </span>
                    <span class="num font-semibold whitespace-nowrap text-positive @min-[920px]:hidden">
                        <span v-if="receipt.currency === 'UAH'" class="font-sans text-12 font-normal text-ink-3">{{ t('money.receiptCard.netShort') }}</span>
                        {{ netUah(receipt, feesOf(receipt)?.net) !== null ? formatCompact(netUah(receipt, feesOf(receipt)?.net), 'UAH') : '—' }}
                    </span>
                </span>
                <span class="hidden @min-[920px]:block" />
                <span class="num hidden text-right font-semibold whitespace-nowrap text-positive @min-[920px]:block">
                    <span class="font-sans text-12 font-normal text-ink-3">{{ t('money.receiptCard.netShort') }}</span>
                    {{ netUah(receipt, feesOf(receipt)?.net) !== null ? formatCompact(netUah(receipt, feesOf(receipt)?.net), 'UAH') : '—' }}
                </span>
                <span class="num text-right whitespace-nowrap">
                    <span class="font-sans text-12 text-ink-3">{{ t('money.receiptCard.feesShort') }}</span>
                    {{ feesOf(receipt)?.fees !== null ? formatCompact(feesOf(receipt)!.fees!, receipt.currency) : '—' }}
                </span>
                <span />
            </div>
        </div>
    </section>
</template>
