<script setup lang="ts">
import { computed } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, Payment, TaxMonths, TaxReconciliation, TaxType } from '@/api/types';
import MonthCard from '@/components/payment/MonthCard.vue';
import ReceiptTaxCard from '@/components/payment/ReceiptTaxCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { formatDate, monthName, monthNameInText } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney, sumAmounts } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';
import { amountsToPay } from '@/utils/taxes';

/**
 * Податки, «Нарахування» (M-Taxes, design.md §16.5): one quarter. «Податки за квартал»: ЄП, ВЗ and ЄСВ, each with its
 * deadline (ЄСВ is due with its quarter however it is paid) or «✓ закрито», and what is accrued, paid and left to pay,
 * with what is sent and not confirmed yet; ЄСВ adds «Сплатити ЄСВ за {місяць}», the earliest month not fully paid.
 * Then «По надходженнях»: the quarter's receipts by month, each with its ЄП and ВЗ.
 */
const TAXES: TaxType[] = ['single_tax', 'military_levy', 'social_contribution'];

const { year, quarter } = defineProps<{ year: number; quarter: number }>();

const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [reconciliations, months, receipts] = await Promise.all([
        Promise.all(TAXES.map((type) => api.get<ApiResponse<TaxReconciliation>>(path(`taxes/${year}`), { tax_type: type }, signal).then((response) => response.data))),
        api.get<ApiResponse<TaxMonths>>(path(`taxes/${year}/months`), undefined, signal),
        api.all<Payment>(path('payments'), {
            filter: { period_year: year, period_quarter: quarter, kind: 'invoice' },
            include: ['invoice.client', 'taxPayments'],
            sort: 'received_at',
        }, signal),
    ]);

    return { reconciliations, months: months.data.months, receipts };
}, [profileId, () => year, () => quarter]);

/** A row per tax: the quarter's figures, what is left to pay and whether it is settled. */
const rows = computed(() => (data.value?.reconciliations ?? []).map((reconciliation) => {
    const figures = reconciliation.quarters.find((item) => item.quarter === quarter);
    const owed = figures?.owed ?? '0.00';
    const toPay = amountsToPay(reconciliation).get(quarter) ?? '0.00';

    return {
        type: reconciliation.tax_type,
        owed,
        paid: figures?.paid ?? '0.00',
        pending: figures?.pending ?? '0.00',
        toPay,
        dueAt: figures?.due_at ?? null,
        settled: compareAmounts(owed, '0') > 0 && compareAmounts(toPay, '0') === 0,
    };
}));

/** The earliest month of the quarter, whose ЄСВ isn't fully paid (sent counts as paid here). */
const esvMonth = computed(() => (data.value?.months ?? [])
    .filter((month) => month.quarter === quarter)
    .find((month) => compareAmounts(sumAmounts(month.social_contribution.paid, month.social_contribution.pending), month.social_contribution.owed) < 0)
    ?.month ?? null);

/** The receipts by the month of their income. */
const receiptMonths = computed(() => {
    const months = new Map<number, Payment[]>();

    for (const receipt of data.value?.receipts ?? []) {
        months.set(receipt.period_month, [...(months.get(receipt.period_month) ?? []), receipt]);
    }

    return [...months.entries()].map(([month, receipts]) => ({
        month,
        receipts,
        total: sumAmounts(...receipts.map((receipt) => receipt.uah_amount)),
    }));
});
</script>

<template>
    <LoadState :loading="loading && !data" :error="error" @retry="reload">
        <template v-if="data">
            <section class="flex flex-col gap-2.5">
                <SectionTitle>{{ t('accounting.taxes.forQuarter') }}</SectionTitle>
                <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <div v-for="row in rows" :key="row.type" class="flex flex-col gap-2 py-3.5">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-17 font-semibold">{{ TAX_TYPES[row.type] }}</span>
                            <span v-if="row.settled" class="flex items-center gap-1 text-14 font-medium text-positive">
                                <Icon name="check" :size="16" /> {{ t('accounting.taxes.closed') }}
                            </span>
                            <span v-else-if="row.dueAt" class="text-14 text-ink-2">{{ t('accounting.taxes.dueBy', { date: formatDate(row.dueAt) }) }}</span>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <span class="flex flex-col gap-0.5">
                                <span class="text-12 text-ink-3">{{ t('accounting.taxes.accrued') }}</span>
                                <span class="num text-15 font-semibold">{{ formatAmount(row.owed) }}</span>
                            </span>
                            <span class="flex flex-col gap-0.5">
                                <span class="text-12 text-ink-3">{{ t('accounting.taxes.paid') }}</span>
                                <span class="num text-15 font-semibold">{{ formatAmount(row.paid) }}</span>
                            </span>
                            <span class="flex flex-col gap-0.5">
                                <span class="text-12 text-ink-3">{{ t('accounting.taxes.toPay') }}</span>
                                <span class="num text-15 font-semibold" :class="compareAmounts(row.toPay, '0') > 0 ? 'text-negative' : 'text-positive'">
                                    {{ formatAmount(row.toPay) }}
                                </span>
                            </span>
                        </div>
                        <span v-if="compareAmounts(row.pending, '0') > 0" class="text-13 text-warning-text">
                            {{ t('accounting.taxes.pendingSent', { amount: formatAmount(row.pending) }) }}
                        </span>
                        <RouterLink
                            v-if="row.type === 'social_contribution' && esvMonth !== null && can('create', 'tax-payments')"
                            :to="{ name: 'esv-payment-new', query: { year, month: esvMonth } }"
                            class="flex min-h-11 items-center self-start text-15 font-medium no-underline"
                        >
                            {{ t('accounting.taxes.payEsvFor', { month: monthNameInText(esvMonth) }) }}
                        </RouterLink>
                    </div>
                </div>
            </section>

            <section class="flex flex-col gap-2.5">
                <SectionTitle>{{ t('accounting.taxes.byReceipt') }}</SectionTitle>
                <MonthCard
                    v-for="month in receiptMonths"
                    :key="month.month"
                    :title="monthName(month.month)"
                    :count="t('core.counts.payments', { count: month.receipts.length })"
                    :total="formatMoney(month.total, 'UAH')"
                >
                    <ReceiptTaxCard v-for="receipt in month.receipts" :key="receipt.id" :payment="receipt" pay-row flat />
                </MonthCard>
                <EmptyState v-if="receiptMonths.length === 0" :text="t('accounting.taxes.noReceipts')" />
                <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('accounting.taxes.receiptsHint') }}</p>
            </section>
        </template>
    </LoadState>
</template>
