<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, FeeStatistics, TransferRoute } from '@/api/types';
import FeeRow from '@/components/accounting/FeeRow.vue';
import YearSelect from '@/components/accounting/YearSelect.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { accountLabel } from '@/utils/accounts';
import { currentQuarter, quarterLabel } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney, sumAmounts } from '@/utils/money';
import { PAYMENTS, TRANSFERS, countOf } from '@/utils/plural';

/**
 * Комісії (accounting.md "which platform is best"): what the money paths cost in a year, in hryvnias. The year's
 * income, fees, FX spread and net income, then by quarter, by account, by client and by transfer route, the
 * costliest first, each with its loss in the income it brought.
 */
const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();

const year = computed(() => Number(route.query.year) || currentQuarter().year);
const yearModel = computed({
    get: () => year.value,
    set: (value: number) => void router.replace({ query: { year: value } }),
});

const { data: stats, error, loading, reload } = useLoader(async (signal) => {
    const { data } = await api.get<ApiResponse<FeeStatistics>>(path(`fees/${year.value}`), undefined, signal);

    return data;
}, [profileId, year]);

const totals = computed(() => {
    const quarters = stats.value?.quarters ?? [];

    return {
        income: sumAmounts(...quarters.map((quarter) => quarter.income)),
        fees: sumAmounts(...quarters.map((quarter) => quarter.fees)),
        spread: sumAmounts(...quarters.map((quarter) => quarter.spread)),
        net: sumAmounts(...quarters.map((quarter) => quarter.net_income)),
    };
});

const isEmpty = computed(() => !!stats.value
    && compareAmounts(totals.value.income, '0') === 0
    && stats.value.routes.length === 0
    && stats.value.by_account.length === 0);

/** The year's fees and spread in its income, two decimals. */
const lossPercent = computed(() => (compareAmounts(totals.value.income, '0') > 0
    ? ((Number(totals.value.fees) + Number(totals.value.spread)) / Number(totals.value.income) * 100).toFixed(2)
    : null));


/** A route as Перекази names it: the destination's currency only, for a sale of currency. */
function routeLabel(transferRoute: TransferRoute): string {
    const to = transferRoute.currency_sent !== transferRoute.currency_received
        ? transferRoute.currency_received
        : accountLabel(transferRoute.to_account_name, transferRoute.currency_received);

    return `${accountLabel(transferRoute.from_account_name, transferRoute.currency_sent)} → ${to}`;
}

const grid = 'grid grid-cols-[52px_1fr_1fr_1fr] items-center gap-2 px-3.5';
</script>

<template>
    <SubScreen title="" muted :back="{ name: 'more' }">
        <div class="-mt-1 flex flex-col gap-1">
            <div class="flex items-center justify-between gap-3">
                <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">Комісії</h1>
                <YearSelect v-model="yearModel" />
            </div>
            <p class="m-0 text-14 text-ink-3">Скільки коштує шлях грошей від клієнта до гривні: комісії й курсовий спред.</p>
        </div>

        <LoadState :loading="loading && !stats" :error="error" @retry="reload">
            <template v-if="stats">
                <EmptyState v-if="isEmpty" :text="`За ${year} рік надходжень і переказів немає.`" />

                <template v-else>
                    <section class="grid grid-cols-2 gap-2" aria-label="Підсумки року">
                        <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                            <span class="text-12 text-ink-3">Дохід</span>
                            <span class="num text-16 font-semibold">{{ formatMoney(totals.income, 'UAH') }}</span>
                        </div>
                        <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                            <span class="text-12 text-ink-3">Чистий дохід</span>
                            <span class="num text-16 font-semibold">{{ formatMoney(totals.net, 'UAH') }}</span>
                        </div>
                        <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                            <span class="text-12 text-ink-3">Комісії</span>
                            <span class="num text-16 font-semibold">{{ formatMoney(totals.fees, 'UAH') }}</span>
                        </div>
                        <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                            <span class="text-12 text-ink-3">Курсовий спред</span>
                            <span class="num text-16 font-semibold">{{ formatMoney(totals.spread, 'UAH') }}</span>
                        </div>
                    </section>
                    <p class="-mt-1 m-0 text-12 leading-[1.45] text-ink-3">
                        <template v-if="lossPercent !== null">Втрати на комісіях і спреді — <span class="num font-semibold">{{ lossPercent }} %</span> доходу. </template>
                        Чистий дохід — це дохід мінус комісії, ЄП, ВЗ і ЄСВ; спред показано окремо, бо це втрата на обміні, а не комісія.
                    </p>

                    <section class="flex flex-col gap-2">
                        <SectionTitle>За кварталами</SectionTitle>
                        <div class="flex flex-col overflow-hidden rounded-card border border-line bg-surface">
                            <div :class="grid" class="border-b border-divider bg-paper-light py-2.5 text-12 font-semibold text-ink-3">
                                <span>Квартал</span>
                                <span class="text-right">Дохід</span>
                                <span class="text-right">Втрати</span>
                                <span class="text-right">Чистий</span>
                            </div>
                            <div
                                v-for="quarter in stats.quarters"
                                :key="quarter.quarter"
                                :class="grid"
                                class="border-b border-divider py-3 text-13 last:border-b-0"
                            >
                                <span class="font-medium">{{ quarterLabel(quarter.quarter) }}</span>
                                <span class="num text-right">{{ formatAmount(quarter.income) }}</span>
                                <span class="num text-right">{{ formatAmount(sumAmounts(quarter.fees, quarter.spread)) }}</span>
                                <span class="num text-right font-semibold">{{ formatAmount(quarter.net_income) }}</span>
                            </div>
                        </div>
                    </section>

                    <section v-if="stats.by_account.length" class="flex flex-col gap-2">
                        <SectionTitle>За рахунками</SectionTitle>
                        <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                            <FeeRow
                                v-for="group in stats.by_account"
                                :key="group.id ?? 0"
                                :title="accountLabel(group.name, group.currency)"
                                :percent="group.loss_percent"
                                :fees="group.fees"
                                :spread="group.spread"
                                :to="group.id !== null ? { name: 'account', params: { id: group.id } } : undefined"
                            >
                                <template v-if="group.receipts > 0">{{ countOf(group.receipts, PAYMENTS) }} · <span class="num">{{ formatMoney(group.income, 'UAH') }}</span></template>
                                <template v-else>лише перекази</template>
                            </FeeRow>
                        </div>
                    </section>

                    <section v-if="stats.by_client.length" class="flex flex-col gap-2">
                        <SectionTitle>За клієнтами</SectionTitle>
                        <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                            <FeeRow
                                v-for="group in stats.by_client"
                                :key="group.id ?? 0"
                                :title="group.name ?? 'Без клієнта'"
                                :percent="group.loss_percent"
                                :fees="group.fees"
                                :spread="group.spread"
                                :to="group.id !== null ? { name: 'client', params: { id: group.id } } : undefined"
                            >
                                <template v-if="group.receipts > 0">{{ countOf(group.receipts, PAYMENTS) }} · <span class="num">{{ formatMoney(group.income, 'UAH') }}</span></template>
                                <template v-else>лише перекази</template>
                            </FeeRow>
                        </div>
                    </section>

                    <section v-if="stats.routes.length" class="flex flex-col gap-2">
                        <SectionTitle>
                            За маршрутами
                            <template #aside>
                                <RouterLink :to="{ name: 'transfers' }" class="flex min-h-11 items-center text-14 font-medium no-underline">Перекази</RouterLink>
                            </template>
                        </SectionTitle>
                        <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                            <FeeRow
                                v-for="transferRoute in stats.routes"
                                :key="`${transferRoute.from_account_id}-${transferRoute.to_account_id}`"
                                :title="routeLabel(transferRoute)"
                                :percent="transferRoute.loss_percent"
                                :fees="transferRoute.fees_uah"
                                :spread="transferRoute.spread_uah"
                            >
                                {{ countOf(transferRoute.count, TRANSFERS) }} · <span class="num">{{ formatMoney(transferRoute.sent, transferRoute.currency_sent) }}</span>
                            </FeeRow>
                        </div>
                    </section>

                    <p v-if="stats.unknown_fees_count" class="m-0 text-12 text-ink-3">
                        Без курсу НБУ не пораховано комісій переказів: {{ stats.unknown_fees_count }}.
                    </p>
                </template>
            </template>
        </LoadState>
    </SubScreen>
</template>
