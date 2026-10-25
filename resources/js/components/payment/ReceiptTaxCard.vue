<script setup lang="ts">
import { computed } from 'vue';
import type { Payment, TaxType } from '@/api/types';
import ReceiptHeader from '@/components/payment/ReceiptHeader.vue';
import TaxPaymentBadge from '@/components/taxes/TaxPaymentBadge.vue';
import { t } from '@/i18n';
import { formatAmount } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * A receipt card (design.md §16.4): the head, which links to the receipt, then its ЄП and ВЗ, each with its amount and
 * its payment's status, opening that payment (the recorded one, or a new one). With `payRow`, «Сплатити ЄП і ВЗ за
 * цим надходженням» shows while either is unpaid, and opens the first unpaid one.
 */
type ReceiptTax = Extract<TaxType, 'single_tax' | 'military_levy'>;

const { payment, payRow = false } = defineProps<{
    payment: Payment;
    payRow?: boolean;
}>();

const taxes = computed(() => (['single_tax', 'military_levy'] as ReceiptTax[]).map((type) => {
    const taxPayment = payment.taxPayments?.find((item) => item.tax_type === type) ?? null;

    return {
        type,
        amount: taxPayment?.amount ?? (type === 'single_tax' ? payment.single_tax_uah : payment.military_levy_uah),
        taxPayment,
        to: taxPayment
            ? { name: 'tax-payment', params: { id: taxPayment.id } }
            : { name: 'tax-payment-new', query: { payment: payment.id, tax: type } },
    };
}));

/** The taxes, which aren't paid: not recorded, or recorded as failed. */
const unpaid = computed(() => taxes.value.filter((tax) => tax.taxPayment === null || tax.taxPayment.status === 'failed'));
const payLabel = computed(() => (unpaid.value.length === 2
    ? t('money.receiptCard.payBoth')
    : t('money.receiptCard.payOne', { tax: TAX_TYPES[unpaid.value[0]?.type ?? 'single_tax'] })));
</script>

<template>
    <article class="flex flex-col rounded-card border border-line bg-surface">
        <ReceiptHeader :payment="payment" :to="{ name: 'payment', params: { id: payment.id } }" class="px-4 pt-3.5 pb-3" />
        <div class="mx-4 flex flex-col divide-y divide-divider border-t border-dashed border-line-input">
            <RouterLink
                v-for="tax in taxes"
                :key="tax.type"
                :to="tax.to"
                class="flex items-center gap-2 py-2.5 text-ink no-underline hover:text-ink"
            >
                <span class="w-11 shrink-0 text-14 font-semibold">{{ TAX_TYPES[tax.type] }}</span>
                <span class="num flex-1 text-15 font-semibold" :class="tax.taxPayment?.status === 'failed' ? 'text-ink-3 line-through' : ''">
                    {{ formatAmount(tax.amount) }}
                </span>
                <TaxPaymentBadge :status="tax.taxPayment?.status ?? null" :paid-at="tax.taxPayment?.paid_at" dated />
            </RouterLink>
        </div>
        <RouterLink
            v-if="payRow && unpaid.length"
            :to="unpaid[0]!.to"
            class="flex min-h-12 items-center justify-center border-t border-line px-4 text-15 font-medium text-accent no-underline"
        >
            {{ payLabel }}
        </RouterLink>
        <div v-else class="h-1.5" />
    </article>
</template>
