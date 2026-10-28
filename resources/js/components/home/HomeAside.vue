<script setup lang="ts">
import { computed } from 'vue';
import { api } from '@/api/client';
import type { Alert, ApiResponse, IncomeBookYear, Paginated, TaxLedger, TaxType } from '@/api/types';
import AlertCard from '@/components/alert/AlertCard.vue';
import IncomeLimitBand from '@/components/books/IncomeLimitBand.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { t } from '@/i18n';
import { currentQuarter, formatDayMonth, quarterLabel } from '@/utils/dates';
import { formatAmount, formatMoney, sumAmounts } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * The right-hand column of Головна on desktop (D-Home, design.md §17.4), 360 px: the year's income limit, what the
 * current quarter's taxes still owe (ЄСВ, ЄП, ВЗ with their deadlines, from the tax ledger) and the latest unread
 * notifications, each leading where it says.
 */
const TAXES: TaxType[] = ['social_contribution', 'single_tax', 'military_levy'];

const { profile, profileId, path, markOneRead } = useCurrentProfile();
const now = currentQuarter();

const { data } = useLoader(async (signal) => {
    const [income, ledger, alerts] = await Promise.all([
        api.get<ApiResponse<IncomeBookYear>>(path(`books/${now.year}/income`), {}, signal),
        api.get<ApiResponse<TaxLedger>>(path(`taxes/${now.year}/ledger`), { quarter: now.quarter, status: 'unpaid' }, signal),
        api.get<Paginated<Alert>>(path('alerts'), { filter: { unread: true }, page: { number: 1, size: 3 } }, signal),
    ]);

    return { income: income.data, ledger: ledger.data, alerts: alerts.data, unread: alerts.meta.total };
}, [profileId]);

const taxes = computed(() => TAXES.map((type) => {
    const row = data.value?.ledger.summary.rows.find((item) => item.tax_type === type);

    return { type, amount: row?.accrued ?? '0.00', due: row?.due_at ?? null };
}));

const toPay = computed(() => sumAmounts(...taxes.value.map((tax) => tax.amount)));

async function read(alert: Alert): Promise<void> {
    await api.post(path(`alerts/${alert.id}/read`)).catch(() => undefined);
    markOneRead();
}
</script>

<template>
    <aside class="flex flex-col gap-5">
        <IncomeLimitBand
            v-if="data"
            :ytd="data.income.ytd"
            :percent="data.income.limit.percent"
            :cap="data.income.limit.cap"
            :left="data.income.limit.left"
            :group="profile?.tax_group ?? 3"
        />

        <section v-if="data" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
            <div class="flex items-baseline justify-between">
                <span class="text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">{{ t('shell.overview.taxesIn', { quarter: quarterLabel(now.quarter) }) }}</span>
                <RouterLink :to="{ name: 'taxes' }" class="text-13 font-medium no-underline">{{ t('invoices.home.allTaxes') }}</RouterLink>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <span v-for="tax in taxes" :key="tax.type" class="flex flex-col gap-0.5">
                    <span class="text-12 text-ink-3">{{ TAX_TYPES[tax.type] }}</span>
                    <span class="num text-17 font-semibold">{{ formatAmount(tax.amount) }}</span>
                    <span v-if="tax.due" class="text-11 text-ink-3">{{ t('invoices.table.dueShort', { date: formatDayMonth(tax.due) }) }}</span>
                </span>
            </div>
            <div class="flex items-baseline justify-between border-t border-divider pt-2.5 text-13">
                <span class="text-ink-2">{{ t('invoices.home.toPayFor', { quarter: quarterLabel(now.quarter) }) }}</span>
                <span class="num font-semibold">{{ formatMoney(toPay, 'UAH') }}</span>
            </div>
        </section>

        <section v-if="data && data.alerts.length" class="flex flex-col gap-2">
            <SectionTitle>
                {{ t('shell.alerts.notifications') }}
                <template #aside><span class="text-ink-2">{{ t('invoices.home.unreadCount', { count: data.unread }) }}</span></template>
            </SectionTitle>
            <AlertCard v-for="alert in data.alerts" :key="alert.id" :alert="alert" @read="read" />
        </section>
    </aside>
</template>
