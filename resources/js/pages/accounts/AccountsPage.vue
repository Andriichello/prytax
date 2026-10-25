<script setup lang="ts">
import { computed } from 'vue';
import { api } from '@/api/client';
import type { Account, AccountKind, LimitPeriod } from '@/api/types';
import SubScreen from '@/components/layout/SubScreen.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { ACCOUNT_KINDS } from '@/utils/accounts';
import { formatAmount, formatMoney } from '@/utils/money';

/**
 * Рахунки (S-Accounts): the profile's accounts by kind, with balances from tracked movements, the progress of a
 * limit where one is set, and the money a platform or foreign bank still holds past the repatriation rule.
 */
const KINDS: AccountKind[] = ['platform', 'fop_bank', 'foreign_bank', 'personal'];

/**
 * What a limit counts over its period: the client receipts, «Надходження за місяць».
 */
function limitText(period: LimitPeriod): string {
    const texts: Record<LimitPeriod, string> = {
        month: t('money.accounts.receiptsPerMonth'),
        quarter: t('money.accounts.receiptsPerQuarter'),
        year: t('money.accounts.receiptsPerYear'),
    };

    return texts[period];
}

const { profileId, path, settings } = useCurrentProfile();
const { can } = useSession();

const { data: accounts, error, loading, reload } = useLoader(async (signal) => {
    return api.all<Account>(path('accounts'), { append: ['balance', 'limit_progress', 'late_holdings'] }, signal);
}, [profileId]);

const hasLate = (account: Account): boolean => Boolean(account.late_holdings && Number(account.late_holdings) > 0);

/** When the late money should have left, by the profile's rule: "після кінця кварталу", "довше 30 днів". */
const lateWhen = computed(() => {
    const rule = settings.value?.compliance.platform_repatriation ?? 'quarter_end';
    const days = /^days:(\d+)$/.exec(rule);

    if (!days) {
        return t('money.accounts.afterQuartersEnd');
    }

    return t('money.accounts.longerThanDays', { count: Number(days[1]) });
});

const groups = computed(() => KINDS
    .map((kind) => ({ kind, accounts: (accounts.value ?? []).filter((account) => account.kind === kind) }))
    .filter((group) => group.accounts.length > 0));

function caption(account: Account): string {
    if (!account.is_active) {
        return t('money.accounts.inactiveHistoryKept');
    }

    if (account.kind === 'personal') {
        return t('money.accounts.personalAccountNote', { currency: account.currency });
    }

    if (account.id === settings.value?.taxes.default_account_id) {
        return t('money.accounts.taxAccountNote', { currency: account.currency });
    }

    return t('money.accounts.noLimit');
}
</script>

<template>
    <SubScreen title="" muted :back="{ name: 'more' }">
        <div class="-mt-1 flex flex-col gap-1">
            <div class="flex items-center justify-between gap-3">
                <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ t('money.accounts.title') }}</h1>
                <RouterLink
                    v-if="can('create', 'accounts')"
                    :to="{ name: 'account-new' }"
                    class="flex h-11 items-center gap-1.5 rounded-full bg-accent pr-4 pl-3 text-15 font-semibold text-white no-underline hover:bg-accent-hover hover:text-white"
                >
                    <Icon name="plus" :size="20" />
                    {{ t('core.common.add') }}
                </RouterLink>
            </div>
            <p class="m-0 text-14 leading-[1.45] text-ink-3">
                {{ t('money.accounts.balancesNote') }}
            </p>
        </div>

        <LoadState :loading="loading && !accounts" :error="error" @retry="reload">
            <EmptyState v-if="accounts && accounts.length === 0" :text="t('money.accounts.noAccountsYet')">
                <RouterLink v-if="can('create', 'accounts')" :to="{ name: 'account-new' }" class="flex min-h-11 items-center text-14 font-medium no-underline">
                    {{ t('money.accounts.addAccount') }}
                </RouterLink>
            </EmptyState>

            <section v-for="group in groups" :key="group.kind" class="flex flex-col gap-2" :aria-label="ACCOUNT_KINDS[group.kind].group">
                <SectionTitle>{{ ACCOUNT_KINDS[group.kind].group }}</SectionTitle>
                <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <RouterLink
                        v-for="account in group.accounts"
                        :key="account.id"
                        :to="{ name: 'account', params: { id: account.id } }"
                        class="flex flex-col gap-1.5 py-3.5 no-underline"
                        :class="account.is_active ? 'text-ink hover:text-ink' : 'text-ink-3 hover:text-ink-3'"
                    >
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="min-w-0 truncate text-16 font-medium">{{ account.name }}</span>
                            <span v-if="account.kind === 'personal'" class="num text-16 font-semibold">—</span>
                            <span v-else class="num text-16" :class="account.is_active ? 'font-semibold' : ''">
                                {{ formatMoney(account.balance ?? '0.00', account.currency) }}
                            </span>
                        </span>
                        <template v-if="account.limit_progress && account.is_active">
                            <ProgressBar :percent="Number(account.limit_progress.percent)" :warn-from="80" />
                            <span class="flex items-baseline justify-between gap-3 text-13 text-ink-2">
                                <span>{{ limitText(account.limit_progress.period) }}</span>
                                <span class="num" :class="Number(account.limit_progress.percent) >= 80 ? 'font-semibold text-warning-text' : ''">
                                    {{ formatAmount(account.limit_progress.used) }} / {{ formatAmount(account.limit_progress.limit) }} · {{ account.limit_progress.percent }} %
                                </span>
                            </span>
                        </template>
                        <span v-else class="flex items-baseline justify-between gap-3 text-13">
                            <span :class="account.is_active ? 'text-ink-3' : ''">{{ caption(account) }}</span>
                            <span v-if="hasLate(account)" class="text-warning-text">
                                <span class="num">{{ formatAmount(account.late_holdings) }}</span> {{ lateWhen }}
                            </span>
                        </span>
                        <span v-if="account.limit_progress && account.is_active && hasLate(account)" class="text-right text-13 text-warning-text">
                            <span class="num">{{ formatAmount(account.late_holdings) }}</span> {{ lateWhen }}
                        </span>
                    </RouterLink>
                </div>
            </section>
        </LoadState>
    </SubScreen>
</template>
