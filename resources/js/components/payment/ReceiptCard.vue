<script setup lang="ts">
import { computed } from 'vue';
import type { Payment } from '@/api/types';
import { t } from '@/i18n';
import { formatDate, monthNameInText, quarterLabel } from '@/utils/dates';
import { formatAmount, formatMoney, subtractAmounts } from '@/utils/money';

/**
 * A receipt of an invoice (handoff §6.10): date and account, its hryvnias, the period, the original amount at the
 * NBU rate, and the fee taken at receipt. No ЄП / ВЗ here: they live on the receipt's page and in Облік.
 */
const { payment } = defineProps<{ payment: Payment }>();

/** The fee taken on the way in: what the client paid less what arrived, when both are in one currency. */
const fee = computed(() => (payment.received_currency === payment.currency && payment.received_amount
    ? subtractAmounts(payment.amount, payment.received_amount)
    : null));

</script>

<template>
    <RouterLink
        :to="{ name: 'payment', params: { id: payment.id } }"
        class="flex flex-col gap-1 rounded-card border border-line bg-surface px-3.5 py-3 text-ink no-underline hover:text-ink"
    >
        <span class="flex items-baseline justify-between gap-2">
            <span class="text-15 font-medium">
                {{ formatDate(payment.received_at) }}<template v-if="payment.receivedToAccount"> · {{ payment.receivedToAccount.name }}</template>
            </span>
            <span class="num text-17 font-bold">{{ formatMoney(payment.uah_amount, 'UAH') }}</span>
        </span>
        <span class="flex items-baseline justify-between gap-2 text-12 text-ink-3">
            <span>{{ quarterLabel(payment.period_quarter) }} · {{ monthNameInText(payment.period_month) }}</span>
            <span v-if="payment.currency !== 'UAH'" class="num">
                {{ payment.currency }} {{ formatAmount(payment.amount) }} @ {{ formatAmount(payment.nbu_rate) }}
            </span>
        </span>
        <span v-if="fee !== null" class="mt-2 flex items-baseline justify-between gap-2 border-t border-divider pt-2.5 text-14">
            <span class="text-ink-2">{{ t('money.paymentForm.feeReceipt') }}</span>
            <span class="num font-semibold">{{ formatMoney(fee, payment.currency) }}</span>
        </span>
    </RouterLink>
</template>
