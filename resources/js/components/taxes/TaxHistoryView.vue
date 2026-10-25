<script setup lang="ts">
import { computed } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { api } from '@/api/client';
import type { TaxPayment } from '@/api/types';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import MonthCard from '@/components/payment/MonthCard.vue';
import TaxPaymentBadge from '@/components/taxes/TaxPaymentBadge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { t } from '@/i18n';
import { dateParts, formatDayMonthWords, formatMonthYear, monthNameInText, quarterLabel } from '@/utils/dates';
import { formatAmount, formatMoney, sumAmounts } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * Податки, «Історія сплат» (M-TaxesHistory, design.md §8.4): every tax payment paid in the year, newest first, by the
 * month of its payment date, with the month's total of the payments that didn't fail. A row tells the tax, the amount
 * (struck through when it failed) and the status; what it paid for (the receipt's invoice and client, or ЄСВ's month);
 * and the date with the account (`Monobank ФОП · USD → UAH` from a currency account). It opens the payment.
 */
const { year } = defineProps<{ year: number }>();

const { profileId, path } = useCurrentProfile();

const { data: payments, error, loading, reload } = useLoader((signal) => api.all<TaxPayment>(path('tax-payments'), {
    filter: { paid_at: { gte: `${year}-01-01`, lte: `${year}-12-31` } },
    include: ['payment.invoice.client', 'paidFromAccount'],
    sort: '-paid_at,-id',
}, signal), [profileId, () => year]);

const months = computed(() => {
    const grouped = new Map<number, TaxPayment[]>();

    for (const payment of payments.value ?? []) {
        const month = payment.paid_at ? dateParts(payment.paid_at).month : 0;

        grouped.set(month, [...(grouped.get(month) ?? []), payment]);
    }

    return [...grouped.entries()].map(([month, list]) => ({
        month,
        payments: list,
        total: sumAmounts(...list.filter((payment) => payment.status !== 'failed').map((payment) => payment.amount)),
    }));
});

/** The form of the payment: ЄСВ's, or a receipt's ЄП / ВЗ; a payment of a quarter, as recorded before, has none. */
function linkOf(payment: TaxPayment): RouteLocationRaw | null {
    if (payment.tax_type === 'social_contribution') {
        return payment.period_month ? { name: 'esv-payment', params: { id: payment.id } } : null;
    }

    return payment.payment_id ? { name: 'tax-payment', params: { id: payment.id } } : null;
}

/** «ПриватБанк ФОП · UAH», or from a currency account «Monobank ФОП · USD → UAH». */
function accountOf(payment: TaxPayment): string {
    const account = payment.paidFromAccount;

    if (!account) {
        return '';
    }

    return account.currency === 'UAH' ? `${account.name} · UAH` : `${account.name} · ${account.currency} → UAH`;
}
</script>

<template>
    <LoadState :loading="loading && !payments" :error="error" @retry="reload">
        <section class="flex flex-col gap-2.5">
            <SectionTitle>{{ t('accounting.taxes.historyTitle') }}</SectionTitle>
            <MonthCard
                v-for="month in months"
                :key="month.month"
                :title="month.month ? formatMonthYear(year, month.month) : '—'"
                :count="t('core.counts.taxPayments', { count: month.payments.length })"
                :total="formatMoney(month.total, 'UAH')"
            >
                <component
                    :is="linkOf(payment) ? 'RouterLink' : 'div'"
                    v-for="payment in month.payments"
                    :key="payment.id"
                    :to="linkOf(payment) ?? undefined"
                    class="flex gap-2 bg-surface px-4 py-3 text-ink no-underline hover:text-ink"
                >
                    <span class="min-w-11 shrink-0 text-14 font-semibold whitespace-nowrap">{{ TAX_TYPES[payment.tax_type] }}</span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="flex items-center justify-between gap-2">
                            <span class="num text-16 font-semibold" :class="payment.status === 'failed' ? 'text-ink-3 line-through' : ''">
                                {{ formatAmount(payment.amount) }}
                            </span>
                            <TaxPaymentBadge :status="payment.status" short />
                        </span>
                        <span v-if="payment.tax_type === 'social_contribution'" class="text-14 text-ink-2">
                            {{ payment.period_month
                                ? t('accounting.taxes.forMonth', { month: monthNameInText(payment.period_month) })
                                : t('accounting.taxes.forMonth', { month: quarterLabel(payment.period_quarter) }) }}
                        </span>
                        <span v-else-if="payment.payment?.invoice" class="flex min-w-0 items-center gap-1.5 text-14 text-ink-2">
                            <VirtualMarker v-if="payment.payment.invoice.source === 'virtual'" size="sm" />
                            <span v-else class="num">{{ payment.payment.invoice.number }}</span>
                            <span class="truncate">· {{ payment.payment.invoice.client?.name }}</span>
                        </span>
                        <span v-else class="text-14 text-ink-2">{{ t('accounting.taxes.forMonth', { month: quarterLabel(payment.period_quarter) }) }}</span>
                        <span class="truncate text-13 text-ink-3">
                            {{ formatDayMonthWords(payment.paid_at) }}<template v-if="payment.paidFromAccount"> · {{ accountOf(payment) }}</template>
                        </span>
                    </span>
                </component>
            </MonthCard>
            <EmptyState v-if="months.length === 0" :text="t('accounting.taxes.noTaxPayments', { year })" />
            <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('accounting.taxes.historyHint') }}</p>
        </section>
    </LoadState>
</template>
