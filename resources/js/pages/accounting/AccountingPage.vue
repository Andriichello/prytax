<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, apiUrl } from '@/api/client';
import type { ApiResponse, Payment, PracticeOverview } from '@/api/types';
import QuarterSummaryCard from '@/components/accounting/QuarterSummaryCard.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import MonthCard from '@/components/payment/MonthCard.vue';
import ReceiptTaxCard from '@/components/payment/ReceiptTaxCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import FilterBadge from '@/components/ui/FilterBadge.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PeriodFilterSheet, { type PeriodFilter } from '@/components/ui/PeriodFilterSheet.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SwitchTile from '@/components/ui/SwitchTile.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { useStoredState } from '@/composables/useStoredState';
import { t } from '@/i18n';
import { currentQuarter, monthName, quarterLabel } from '@/utils/dates';
import { formatMoney, sumAmounts } from '@/utils/money';

/**
 * Облік (M-Accounting, design.md §16.5): one quarter. «Підсумок кварталу» (the income limit, the income per currency,
 * the income for taxes and the taxes), then «Надходження»: the receipts by month, by invoice or in one list, each
 * with its ЄП and ВЗ, and its fees and net on demand (the switches apply at once and are remembered per user), and the
 * CSV exports. The period and grouping badges open «Фільтри»; «+ Надходження» opens Інвойс для надходження.
 */
type Grouping = 'month' | 'invoice' | 'none';

interface Group {
    key: string;
    title: string;
    count: string;
    receipts: Payment[];
    total: string;
}

const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const now = currentQuarter();
const year = computed(() => Number(route.params.year) || now.year);
const quarter = computed(() => Number(route.params.quarter) || (year.value === now.year ? now.quarter : 4));

const grouping = useStoredState<Grouping>('accounting.grouping', 'month', (value): value is Grouping => value === 'none' || value === 'month' || value === 'invoice');
const showTaxes = useStoredState('accounting.taxes', true, (value): value is boolean => typeof value === 'boolean');
const showFees = useStoredState('accounting.fees', false, (value): value is boolean => typeof value === 'boolean');

/** The profile and quarter on screen: another quarter's figures are never shown while the chosen one loads. */
const key = computed(() => `${profileId.value}:${year.value}:${quarter.value}:${showFees.value}`);

const { data: loaded, error, loading, reload } = useLoader(async (signal) => {
    const own = key.value;
    const [overview, receipts] = await Promise.all([
        api.get<ApiResponse<PracticeOverview>>(`overview/${year.value}/${quarter.value}`, { with_inactive: 1 }, signal),
        api.all<Payment>(path('payments'), {
            filter: { period_year: year.value, period_quarter: quarter.value, kind: 'invoice' },
            include: ['invoice.client', 'taxPayments'],
            append: showFees.value ? ['metrics'] : [],
            sort: 'received_at',
        }, signal),
    ]);

    return { key: own, card: overview.data.profiles.find((entry) => entry.profile.id === profileId.value) ?? null, receipts };
}, [key]);

const data = computed(() => (loaded.value?.key === key.value ? loaded.value : null));

const groupingOptions = computed<{ value: Grouping; label: string }[]>(() => [
    { value: 'month', label: t('accounting.quarters.byMonth') },
    { value: 'invoice', label: t('accounting.quarters.byInvoice') },
    { value: 'none', label: t('accounting.quarters.ungrouped') },
]);

const filterOpen = ref(false);

function applyFilter(filter: PeriodFilter): void {
    grouping.value = (filter.grouping as Grouping | undefined) ?? grouping.value;
    void router.replace({ name: 'accounting', params: { year: filter.year, quarter: filter.quarter } });
}

const groups = computed<Group[]>(() => {
    const receipts = data.value?.receipts ?? [];
    const byKey = new Map<string, Payment[]>();
    const keyOf = (receipt: Payment): string => (grouping.value === 'invoice' ? `i${receipt.invoice_id}` : `m${receipt.period_month}`);

    for (const receipt of receipts) {
        byKey.set(keyOf(receipt), [...(byKey.get(keyOf(receipt)) ?? []), receipt]);
    }

    return [...byKey.entries()].map(([groupKey, list]) => {
        const invoice = list[0]!.invoice;
        const count = t('core.counts.payments', { count: list.length });

        return {
            key: groupKey,
            title: grouping.value === 'invoice'
                ? (invoice?.source === 'virtual' ? t('invoices.virtual.marker') : invoice?.number ?? '—')
                : monthName(list[0]!.period_month),
            count: grouping.value === 'invoice' && invoice?.client ? `${invoice.client.name} · ${count}` : count,
            receipts: list,
            total: sumAmounts(...list.map((receipt) => receipt.uah_amount)),
        };
    });
});

const total = computed(() => sumAmounts(...(data.value?.receipts ?? []).map((receipt) => receipt.uah_amount)));

const feesOf = (receipt: Payment): { fees: string | null; net: string | null } | null => (showFees.value
    ? { fees: receipt.metrics?.fees ?? null, net: receipt.metrics?.net_income ?? null }
    : null);

const exportQuarter = computed(() => apiUrl(path(`accounting/${year.value}/${quarter.value}/export`)));
const exportYear = computed(() => apiUrl(path(`accounting/${year.value}/export`)));
</script>

<template>
    <TabScreen :title="t('shell.nav.accounting')">
        <template #title-action>
            <RouterLink
                v-if="can('create', 'payments')"
                :to="{ name: 'payment-pick' }"
                class="flex h-11 items-center gap-1.5 rounded-full bg-accent pr-4 pl-3 text-15 font-semibold text-white no-underline hover:bg-accent-hover hover:text-white"
            >
                <Icon name="plus" :size="20" />
                {{ t('core.common.receipt') }}
            </RouterLink>
        </template>
        <template #filters>
            <div class="-mx-4 flex gap-2 overflow-x-auto px-4">
                <FilterBadge :label="`${quarterLabel(quarter)} ${year}`" period @click="filterOpen = true" />
                <FilterBadge :label="groupingOptions.find((option) => option.value === grouping)?.label ?? ''" @click="filterOpen = true" />
            </div>
        </template>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <template v-if="data">
                <section v-if="data.card" class="flex flex-col gap-2.5">
                    <SectionTitle>{{ t('accounting.quarters.summary') }}</SectionTitle>
                    <QuarterSummaryCard :card="data.card" :year="year" :quarter="quarter" />
                </section>

                <section class="flex flex-col gap-2.5">
                    <SectionTitle>{{ t('core.common.receipts') }}</SectionTitle>
                    <div class="grid grid-cols-2 gap-2">
                        <SwitchTile v-model="showTaxes" :label="t('shell.nav.taxes')" />
                        <SwitchTile v-model="showFees" :label="t('accounting.quarters.feesNet')" />
                    </div>

                    <EmptyState v-if="data.receipts.length === 0" :text="t('accounting.quarters.noReceipts', { quarter: quarterLabel(quarter), year })" />

                    <template v-else-if="grouping === 'none'">
                        <div class="flex items-baseline justify-between px-0.5">
                            <span class="text-13 text-ink-3">{{ t('core.counts.payments', { count: data.receipts.length }) }}</span>
                            <span class="num text-13 font-semibold">{{ formatMoney(total, 'UAH') }}</span>
                        </div>
                        <ReceiptTaxCard
                            v-for="receipt in data.receipts"
                            :key="receipt.id"
                            :payment="receipt"
                            :taxes="showTaxes"
                            :fees="feesOf(receipt)"
                        />
                    </template>

                    <template v-else>
                        <MonthCard
                            v-for="group in groups"
                            :key="group.key"
                            :title="group.title"
                            :count="group.count"
                            :total="formatMoney(group.total, 'UAH')"
                        >
                            <ReceiptTaxCard
                                v-for="receipt in group.receipts"
                                :key="receipt.id"
                                :payment="receipt"
                                :taxes="showTaxes"
                                :fees="feesOf(receipt)"
                                flat
                            />
                        </MonthCard>
                    </template>

                    <div class="grid grid-cols-2 gap-2">
                        <a :href="exportQuarter" download class="flex h-12 items-center justify-center gap-2 rounded-control border border-line-input bg-surface text-15 font-medium text-ink no-underline hover:text-ink">
                            <Icon name="download" :size="18" /> {{ t('accounting.quarters.quarterCsv') }}
                        </a>
                        <a :href="exportYear" download class="flex h-12 items-center justify-center gap-2 rounded-control border border-line-input bg-surface text-15 font-medium text-ink no-underline hover:text-ink">
                            <Icon name="download" :size="18" /> {{ t('accounting.quarters.yearCsv') }}
                        </a>
                    </div>
                </section>
            </template>
        </LoadState>

        <PeriodFilterSheet
            v-model:open="filterOpen"
            :value="{ year, quarter, grouping }"
            :groupings="groupingOptions"
            @apply="applyFilter"
        />
    </TabScreen>
</template>
