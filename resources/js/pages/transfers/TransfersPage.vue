<script setup lang="ts">
import { computed } from 'vue';
import { api } from '@/api/client';
import type { AccountTransfer, ApiResponse, Paginated, TransferStats } from '@/api/types';
import SubScreen from '@/components/layout/SubScreen.vue';
import TransferRow from '@/components/transfer/TransferRow.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import InfiniteSentinel from '@/components/ui/InfiniteSentinel.vue';
import LoadState from '@/components/ui/LoadState.vue';
import NewPill from '@/components/ui/NewPill.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { usePagedList } from '@/composables/usePagedList';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { accountLabel } from '@/utils/accounts';
import { currentQuarter, dateParts, monthName } from '@/utils/dates';
import { formatMoney } from '@/utils/money';

/**
 * Перекази (S-Transfers): moves between the profile's own accounts, which aren't income. The year's losses by route
 * (fees and the spread of currency sales), then the transfers by month. App keeps it alive behind an opened transfer.
 */
defineOptions({ name: 'TransfersPage' });

const { profileId, path } = useCurrentProfile();
const { can } = useSession();
const { isDesktop } = useBreakpoint();

const year = currentQuarter().year;

const { data: stats } = useLoader(async (signal) => {
    const { data } = await api.get<ApiResponse<TransferStats>>(path('transfers/stats'), { year }, signal);

    return data;
}, [profileId]);

const list = usePagedList<AccountTransfer>((page, signal) => api.get<Paginated<AccountTransfer>>(path('transfers'), {
    include: ['fromAccount', 'toAccount', 'allocations'],
    append: ['fx_spread', 'effective_rate'],
    page: { number: page, size: 30 },
}, signal), [profileId]);

const months = computed(() => {
    const groups: { key: string; title: string; transfers: AccountTransfer[] }[] = [];

    for (const transfer of list.items.value) {
        const { year: groupYear, month } = dateParts(transfer.received_at);
        const key = `${groupYear}-${month}`;
        const last = groups[groups.length - 1];

        if (last?.key === key) {
            last.transfers.push(transfer);
        } else {
            groups.push({ key, title: `${monthName(month)} · ${groupYear}`, transfers: [transfer] });
        }
    }

    return groups;
});

/** Cheap routes are green, costly ones (1 % and more) red. */
const lossTone = (percent: string): string => (Number(percent) >= 1 ? 'text-negative' : Number(percent) < 0.5 ? 'text-positive' : '');
</script>

<template>
    <SubScreen
        :title="isDesktop ? t('money.transfers.title') : ''"
        :muted="!isDesktop"
        :subtitle="isDesktop ? t('money.transfers.intro') : undefined"
        width="list"
        :back="{ name: 'more' }"
    >
        <template v-if="isDesktop && can('create', 'account-transfers')" #action>
            <NewPill :to="{ name: 'transfer-new' }" />
        </template>

        <div v-if="!isDesktop" class="-mt-1 flex flex-col gap-1">
            <div class="flex items-center justify-between gap-3">
                <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ t('money.transfers.title') }}</h1>
                <NewPill v-if="can('create', 'account-transfers')" :to="{ name: 'transfer-new' }" />
            </div>
            <p class="m-0 text-14 text-ink-3">{{ t('money.transfers.intro') }}</p>
        </div>

        <section v-if="stats && stats.routes.length" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
            <SectionTitle as="span">{{ t('money.transfers.lossesByRoute', { year }) }}</SectionTitle>
            <div class="grid grid-cols-2 gap-2">
                <div class="flex flex-col gap-1 rounded-control bg-paper p-3">
                    <span class="text-13 text-ink-3">{{ t('money.transfers.fees') }}</span>
                    <span class="num text-17 font-bold">{{ formatMoney(stats.fees_uah, 'UAH') }}</span>
                </div>
                <div class="flex flex-col gap-1 rounded-control bg-paper p-3">
                    <span class="text-13 text-ink-3">{{ t('money.transfers.fxSpread') }}</span>
                    <span class="num text-17 font-bold">{{ formatMoney(stats.spread_uah, 'UAH') }}</span>
                </div>
            </div>
            <div class="flex flex-col divide-y divide-divider border-t border-divider">
                <div
                    v-for="route in stats.routes"
                    :key="`${route.from_account_id}-${route.to_account_id}`"
                    class="flex items-center justify-between gap-3 py-2.5 text-14"
                >
                    <span class="min-w-0">
                        {{ accountLabel(route.from_account_name, route.currency_sent) }} →
                        {{ route.currency_sent !== route.currency_received ? route.currency_received : accountLabel(route.to_account_name, route.currency_received) }}
                    </span>
                    <span class="num shrink-0 font-semibold" :class="lossTone(route.loss_percent)">{{ route.loss_percent }} %</span>
                </div>
            </div>
            <p v-if="stats.unknown_fees_count" class="m-0 text-12 text-ink-3">
                {{ t('money.transfers.feesWithoutRate', { count: stats.unknown_fees_count }) }}
            </p>
            <RouterLink :to="{ name: 'fees', query: { year } }" class="flex min-h-11 items-center gap-1 self-start text-14 font-medium no-underline">
                {{ t('shell.more.feesNetIncome') }}
                <Icon name="chevronRight" :size="16" />
            </RouterLink>
        </section>

        <LoadState :loading="list.loading.value && list.items.value.length === 0" :error="list.error.value" @retry="list.reload">
            <EmptyState v-if="!list.loading.value && list.items.value.length === 0" :text="t('money.transfers.noTransfersYet')">
                <RouterLink v-if="can('create', 'account-transfers')" :to="{ name: 'transfer-new' }" class="flex min-h-11 items-center text-14 font-medium no-underline">
                    {{ t('money.transfers.recordTransfer') }}
                </RouterLink>
            </EmptyState>

            <section v-for="month in months" :key="month.key" class="flex flex-col gap-2" :aria-label="month.title">
                <SectionTitle class="mt-1.5">{{ month.title }}</SectionTitle>
                <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <TransferRow v-for="transfer in month.transfers" :key="transfer.id" :transfer="transfer" />
                </div>
            </section>

            <InfiniteSentinel v-if="list.hasMore.value" :loading="list.loadingMore.value" :failed="Boolean(list.moreError.value)" @visible="list.loadMore" />
        </LoadState>
    </SubScreen>
</template>
