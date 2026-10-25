<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router';
import type { TaxPayment } from '@/api/types';
import TaxPaymentBadge from '@/components/taxes/TaxPaymentBadge.vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';
import { formatDate } from '@/utils/dates';
import { formatAmount } from '@/utils/money';

/**
 * A tax of a receipt on its page (design.md §16.5, «Сплата податків»): the tax (a 44 px label), the amount, the status
 * and, under them, the payment's date and account; unpaid, the computed amount with the faded placeholder line
 * «дд.мм.рррр · Рахунок · № платіжки». The row opens the payment: the recorded one, or a new one.
 */
const { label, amount, taxPayment, to } = defineProps<{
    label: string;
    amount: string;
    taxPayment: TaxPayment | null;
    to: RouteLocationRaw;
}>();
</script>

<template>
    <RouterLink :to="to" class="flex items-center gap-2 py-3 text-ink no-underline hover:text-ink">
        <span class="w-11 shrink-0 self-start text-16 font-semibold">{{ label }}</span>
        <span class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="flex items-center justify-between gap-2">
                <span class="num text-16 font-semibold" :class="taxPayment?.status === 'failed' ? 'line-through' : ''">
                    {{ formatAmount(taxPayment?.amount ?? amount) }}
                </span>
                <TaxPaymentBadge :status="taxPayment?.status ?? null" />
            </span>
            <span v-if="taxPayment" class="truncate text-13 text-ink-2">
                {{ taxPayment.paid_at ? formatDate(taxPayment.paid_at) : '—' }}<template v-if="taxPayment.paidFromAccount"> · {{ taxPayment.paidFromAccount.name }} · {{ taxPayment.paidFromAccount.currency }}</template><template v-if="taxPayment.reference"> · № {{ taxPayment.reference }}</template>
            </span>
            <span v-else class="truncate text-13 text-ink-3 opacity-40">{{ t('money.payment.taxPlaceholder') }}</span>
        </span>
        <Icon name="chevronRight" :size="20" class="shrink-0 text-ink-3" />
    </RouterLink>
</template>
