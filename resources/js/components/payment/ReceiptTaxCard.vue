<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import type { Payment, TaxType } from '@/api/types';
import ReceiptHeader from '@/components/payment/ReceiptHeader.vue';
import TaxPaymentBadge from '@/components/taxes/TaxPaymentBadge.vue';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { formatAmount, formatCompact } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * A receipt card (design.md §16.4): the head, which links to the receipt, then its ЄП and ВЗ, each with its amount and
 * its payment's status, opening that payment (the recorded one, or a new one, for a user who can record it): ЄП and
 * ВЗ are paid one by one from their rows. Облік's switches hide the taxes (`taxes`) and add the fees and what is left
 * net (`fees`: «Ком.», «Чисто»). Inside a month card it is `flat`, without its own border.
 */
type ReceiptTax = Extract<TaxType, 'single_tax' | 'military_levy'>;

const { payment, flat = false, taxes: showTaxes = true, fees = null } = defineProps<{
    payment: Payment;
    flat?: boolean;
    taxes?: boolean;
    /** The receipt's fees and net income, in its currency. */
    fees?: { fees: string | null; net: string | null } | null;
}>();

const { can } = useSession();

const canPay = computed(() => can('create', 'tax-payments'));

const taxes = computed(() => (['single_tax', 'military_levy'] as ReceiptTax[]).map((type) => {
    const taxPayment = payment.taxPayments?.find((item) => item.tax_type === type) ?? null;

    return {
        type,
        amount: taxPayment?.amount ?? (type === 'single_tax' ? payment.single_tax_uah : payment.military_levy_uah),
        taxPayment,
        to: taxPayment
            ? { name: 'tax-payment', params: { id: taxPayment.id } }
            : canPay.value ? { name: 'tax-payment-new', query: { payment: payment.id, tax: type } } : null,
    };
}));
</script>

<template>
    <article class="flex flex-col bg-surface" :class="flat ? '' : 'rounded-card border border-line'">
        <ReceiptHeader :payment="payment" :to="{ name: 'payment', params: { id: payment.id } }" class="px-4 pt-3.5 pb-3" />
        <div v-if="showTaxes || fees" class="mx-4 flex flex-col divide-y divide-divider border-t border-dashed border-line-input">
            <component
                :is="tax.to ? RouterLink : 'div'"
                v-for="tax in showTaxes ? taxes : []"
                :key="tax.type"
                :to="tax.to ?? undefined"
                class="flex items-center gap-2 py-2.5 text-ink no-underline hover:text-ink"
            >
                <span class="min-w-11 shrink-0 text-14 font-semibold whitespace-nowrap">{{ TAX_TYPES[tax.type] }}</span>
                <span class="num flex-1 text-15 font-semibold" :class="tax.taxPayment?.status === 'failed' ? 'text-ink-3 line-through' : ''">
                    {{ formatAmount(tax.amount) }}
                </span>
                <TaxPaymentBadge :status="tax.taxPayment?.status ?? null" :paid-at="tax.taxPayment?.paid_at" short />
            </component>
            <template v-if="fees">
                <span class="flex items-center gap-2 py-2.5">
                    <span class="min-w-11 shrink-0 text-14 font-semibold whitespace-nowrap">{{ t('money.receiptCard.feesShort') }}</span>
                    <span class="num flex-1 text-15 font-semibold">{{ fees.fees !== null ? formatCompact(fees.fees, payment.currency) : '—' }}</span>
                </span>
                <span class="flex items-center gap-2 py-2.5">
                    <span class="min-w-11 shrink-0 text-14 font-semibold whitespace-nowrap">{{ t('money.receiptCard.netShort') }}</span>
                    <span class="num flex-1 text-15 font-semibold text-positive">{{ fees.net !== null ? formatCompact(fees.net, payment.currency) : '—' }}</span>
                </span>
            </template>
        </div>
        <div class="h-1.5" />
    </article>
</template>
