<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, apiUrl } from '@/api/client';
import type { AccountingInvoice, AccountingQuarter, AccountingRow, ApiResponse } from '@/api/types';
import AccountingPaymentRow from '@/components/accounting/AccountingPaymentRow.vue';
import QuarterSummary from '@/components/accounting/QuarterSummary.vue';
import YearSelect from '@/components/accounting/YearSelect.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import SwitchTile from '@/components/ui/SwitchTile.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useStoredState } from '@/composables/useStoredState';
import { t } from '@/i18n';
import { currentQuarter, monthName, quarterLabel } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney, sumAmounts } from '@/utils/money';

/**
 * Облік, Квартали (S-Accounting, handoff §7): a quarter's receipts grouped by month, by invoice or not at all, with
 * ЄП / ВЗ and fees / net shown on demand (remembered per user), and the quarter's summary with its cross-checks.
 */
type Grouping = 'none' | 'month' | 'invoice';

interface Group {
    key: string;
    rows: AccountingRow[];
    total: string;
    count: number;
    month?: number;
    invoice?: AccountingInvoice | null;
}

const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();

const now = currentQuarter();
const year = computed(() => Number(route.params.year) || now.year);
const quarter = computed(() => Number(route.params.quarter) || (year.value === now.year ? now.quarter : 4));

const grouping = useStoredState<Grouping>('accounting.grouping', 'month', (value): value is Grouping => value === 'none' || value === 'month' || value === 'invoice');
const showTaxes = useStoredState('accounting.taxes', true, (value): value is boolean => typeof value === 'boolean');
const showFees = useStoredState('accounting.fees', false, (value): value is boolean => typeof value === 'boolean');

/** The profile and quarter on screen: another quarter's figures are never shown while the chosen one loads. */
const key = computed(() => `${profileId.value}:${year.value}:${quarter.value}`);

const { data: loaded, error, loading, reload } = useLoader(async (signal) => {
    const own = key.value;
    const { data: quarterData } = await api.get<ApiResponse<AccountingQuarter>>(path(`accounting/${year.value}/${quarter.value}`), undefined, signal);

    return { key: own, quarter: quarterData };
}, [key]);

const data = computed(() => (loaded.value?.key === key.value ? loaded.value.quarter : null));

function go(nextYear: number, nextQuarter: number): void {
    void router.replace({ name: 'accounting', params: { year: nextYear, quarter: nextQuarter } });
}

const yearModel = computed({
    get: () => year.value,
    set: (value: number) => go(value, value === now.year ? Math.min(quarter.value, now.quarter) : quarter.value),
});

const quarterModel = computed({
    get: () => quarter.value,
    set: (value: number) => go(year.value, value),
});

function summarize(key: string, rows: AccountingRow[]): Group {
    return {
        key,
        rows,
        total: sumAmounts(...rows.map((row) => row.uah_amount)),
        count: rows.filter((row) => row.type === 'payment').length,
    };
}

const groups = computed<Group[]>(() => {
    const rows = data.value?.rows ?? [];

    if (grouping.value === 'month') {
        const months = [...new Set(rows.map((row) => row.month))].sort((a, b) => a - b);

        return months.map((month) => ({ ...summarize(`m${month}`, rows.filter((row) => row.month === month)), month }));
    }

    if (grouping.value === 'invoice') {
        const keys = [...new Set(rows.map((row) => row.invoice_id ?? 0))];

        return keys.map((invoiceId) => ({
            ...summarize(`i${invoiceId}`, rows.filter((row) => (row.invoice_id ?? 0) === invoiceId)),
            invoice: data.value?.invoices.find((invoice) => invoice.id === invoiceId) ?? null,
        }));
    }

    return [];
});

const total = computed(() => sumAmounts(...(data.value?.rows ?? []).map((row) => row.uah_amount)));
const paymentsCount = computed(() => (data.value?.rows ?? []).filter((row) => row.type === 'payment').length);

const exportQuarter = computed(() => apiUrl(path(`accounting/${year.value}/${quarter.value}/export`)));
const exportYear = computed(() => apiUrl(path(`accounting/${year.value}/export`)));

const groupingOptions = computed<{ value: Grouping; label: string }[]>(() => [
    { value: 'none', label: t('accounting.quarters.none') },
    { value: 'month', label: t('core.common.month') },
    { value: 'invoice', label: t('core.common.invoice') },
]);

const isPaid = (invoice: AccountingInvoice): boolean => invoice.status === 'paid' || compareAmounts(invoice.remaining, '0') <= 0;
</script>

<template>
    <TabScreen :title="t('shell.nav.accounting')">
        <template #title-action>
            <YearSelect v-model="yearModel" />
        </template>

        <SegmentedControl
            v-model="quarterModel"
            :label="t('core.common.quarter')"
            :options="[1, 2, 3, 4].map((value) => ({ value, label: quarterLabel(value) }))"
        />


        <div class="flex items-center justify-between gap-2">
            <span id="grouping" class="text-13 text-ink-3">{{ t('accounting.quarters.groupBy') }}</span>
            <div role="group" aria-labelledby="grouping" class="flex gap-1.5">
                <button
                    v-for="option in groupingOptions"
                    :key="option.value"
                    type="button"
                    :aria-pressed="grouping === option.value ? 'true' : 'false'"
                    class="flex min-h-9 items-center rounded-full border px-3 text-13"
                    :class="grouping === option.value ? 'border-ink bg-ink font-medium text-white' : 'border-line-input bg-surface'"
                    @click="grouping = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <span id="show-in-list" class="text-13 text-ink-3">{{ t('accounting.quarters.showList') }}</span>
            <div role="group" aria-labelledby="show-in-list" class="flex gap-2">
                <SwitchTile v-model="showTaxes" :label="t('shell.nav.taxes')" />
                <SwitchTile v-model="showFees" :label="t('accounting.quarters.feesNet')" />
            </div>
        </div>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <template v-if="data">
                <EmptyState v-if="data.rows.length === 0" :text="t('accounting.quarters.noReceipts', { quarter: quarterLabel(quarter), year })" />

                <template v-else-if="grouping === 'none'">
                    <div class="flex items-baseline justify-between px-0.5">
                        <span class="text-13 text-ink-3">{{ t('accounting.quarters.countOldestFirst', { count: t('core.counts.payments', { count: paymentsCount }) }) }}</span>
                        <span class="num text-13 font-semibold">{{ formatMoney(total, 'UAH') }}</span>
                    </div>
                    <div class="flex flex-col gap-2">
                        <div v-for="row in data.rows" :key="`${row.type}-${row.id}`" class="overflow-hidden rounded-card border border-line bg-surface">
                            <AccountingPaymentRow :row="row" :show-taxes="showTaxes" :show-fees="showFees" />
                        </div>
                    </div>
                </template>

                <div v-else class="mt-1 flex flex-col gap-4.5">
                    <section
                        v-for="group in groups"
                        :key="group.key"
                        class="overflow-hidden rounded-card border border-line bg-surface"
                        :aria-label="group.month ? monthName(group.month) : group.invoice?.number ?? t('accounting.quarters.noInvoice')"
                    >
                        <div v-if="group.month" class="flex items-center justify-between gap-3 bg-ink px-4 py-3.5 text-white">
                            <span class="flex flex-col gap-0.5">
                                <h2 class="m-0 text-17 font-semibold">{{ monthName(group.month) }}</h2>
                                <span class="text-12 text-on-ink-3">{{ t('core.counts.payments', { count: group.count }) }}</span>
                            </span>
                            <span class="num text-18 font-bold">{{ formatMoney(group.total, 'UAH') }}</span>
                        </div>

                        <component
                            :is="group.invoice ? 'RouterLink' : 'div'"
                            v-else
                            :to="group.invoice ? { name: 'invoice', params: { id: group.invoice.id } } : undefined"
                            class="flex items-center justify-between gap-3 bg-ink px-4 py-3.5 text-white no-underline hover:text-white"
                        >
                            <span class="flex min-w-0 flex-col gap-[3px]">
                                <h2 class="num m-0 text-16 font-semibold">{{ group.invoice?.number ?? t('accounting.quarters.noInvoice') }}</h2>
                                <span v-if="group.invoice" class="truncate text-12 text-on-ink-3">{{ group.invoice.client_name }}</span>
                                <span v-if="group.invoice && isPaid(group.invoice)" class="text-12 text-on-ink-3">
                                    <Translated :text="t('accounting.quarters.paidInFull')">
                                        <template #total><span class="num">{{ formatMoney(group.invoice.total, group.invoice.currency) }}</span></template>
                                    </Translated>
                                </span>
                                <span v-else-if="group.invoice" class="text-12 text-warning-on-ink">
                                    <Translated :text="t('money.invoicePicker.remainingOfTotal')">
                                        <template #remaining><span class="num">{{ formatAmount(group.invoice.remaining) }}</span></template>
                                        <template #total><span class="num">{{ formatMoney(group.invoice.total, group.invoice.currency) }}</span></template>
                                    </Translated>
                                </span>
                                <span v-else class="text-12 text-on-ink-3">{{ t('accounting.quarters.advancesOtherIncome') }}</span>
                            </span>
                            <span class="flex shrink-0 flex-col items-end gap-[3px]">
                                <span class="num text-18 font-bold">{{ formatMoney(group.total, 'UAH') }}</span>
                                <span class="text-12 text-on-ink-3">{{ t('core.counts.payments', { count: group.count }) }}</span>
                            </span>
                        </component>

                        <div class="flex flex-col gap-1.5 bg-paper">
                            <div v-for="row in group.rows" :key="`${row.type}-${row.id}`" class="bg-surface">
                                <AccountingPaymentRow :row="row" :show-taxes="showTaxes" :show-fees="showFees" :by-invoice="grouping === 'invoice'" />
                            </div>
                        </div>
                    </section>
                </div>

                <QuarterSummary :summary="data.summary" />

                <div class="grid grid-cols-2 gap-2">
                    <a :href="exportQuarter" download class="flex h-12 items-center justify-center gap-2 rounded-control border border-line-input bg-surface text-15 font-medium text-ink no-underline hover:text-ink">
                        <Icon name="download" :size="18" /> {{ t('accounting.quarters.quarterCsv') }}
                    </a>
                    <a :href="exportYear" download class="flex h-12 items-center justify-center gap-2 rounded-control border border-line-input bg-surface text-15 font-medium text-ink no-underline hover:text-ink">
                        <Icon name="download" :size="18" /> {{ t('accounting.quarters.yearCsv') }}
                    </a>
                </div>
            </template>
        </LoadState>
    </TabScreen>
</template>
