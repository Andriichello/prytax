<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '@/api/client';
import type { Account, AccountStatement, ApiResponse, StatementEntry } from '@/api/types';
import SubScreen from '@/components/layout/SubScreen.vue';
import CopyRow from '@/components/ui/CopyRow.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PeriodStepper from '@/components/ui/PeriodStepper.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { ACCOUNT_KINDS } from '@/utils/accounts';
import { currentQuarter, formatDate, formatDayMonth, quarterOf, quarterLabel } from '@/utils/dates';
import { formatAmount, formatMoney } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * Рахунок (S-AccountDetail): the balance from tracked movements with what came in and went out this year, the
 * receipts whose money is still here (late ones marked), the history of movements, and the requisites printed on
 * invoices, each copyable.
 */
const MOVEMENTS = {
    bank_fee: 'Комісія банку',
    interest: 'Відсотки',
    expense: 'Витрата',
    owner_withdrawal: 'Зняття власником',
    other: 'Інший рух',
} as const;

const route = useRoute();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const id = computed(() => Number(route.params.id));
const now = currentQuarter().year;
const year = ref(now);

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [account, statement] = await Promise.all([
        api.get<ApiResponse<Account>>(path(`accounts/${id.value}`), undefined, signal),
        api.get<ApiResponse<AccountStatement>>(path(`accounts/${id.value}/statement`), { year: year.value }, signal),
    ]);

    return { account: account.data, statement: statement.data };
}, [profileId, id, year]);

const account = computed(() => data.value?.account ?? null);
const statement = computed(() => data.value?.statement ?? null);

const mask = (value: string): string => {
    const digits = value.replace(/\s/g, '');

    return digits.length > 8 ? `•••• •••• ${digits.slice(-4)}` : `•••• ${digits.slice(-4)}`;
};

function entryTitle(entry: StatementEntry): string {
    switch (entry.type) {
        case 'receipt':
            return `Надходження · ${entry.title ?? entry.invoice_number ?? ''}`;
        case 'transfer_in':
            return `Переказ ← ${entry.title}`;
        case 'transfer_out':
            return `Переказ → ${entry.title}`;
        case 'tax':
            return `Сплата ${TAX_TYPES[entry.title]}`;
        case 'refund':
            return `Повернення клієнту${entry.title ? ` · ${entry.title}` : ''}`;
        default:
            return entry.title ? `${MOVEMENTS[entry.category]} · ${entry.title}` : MOVEMENTS[entry.category];
    }
}

function entryLink(entry: StatementEntry) {
    switch (entry.type) {
        case 'receipt':
        case 'refund':
            return { name: 'payment', params: { id: entry.payment_id } };
        case 'transfer_in':
        case 'transfer_out':
            return { name: 'transfer', params: { id: entry.transfer_id } };
        default:
            return null;
    }
}
</script>

<template>
    <SubScreen title="Рахунок" muted :back="{ name: 'accounts' }">
        <template #action>
            <RouterLink
                v-if="account && can('update', 'accounts')"
                :to="{ name: 'account-edit', params: { id } }"
                class="flex min-h-11 items-center px-3 text-15 font-medium no-underline"
            >
                Змінити
            </RouterLink>
        </template>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <template v-if="account && statement">
                <div class="-mt-1 flex flex-col gap-1.5">
                    <div class="flex gap-2">
                        <StatusBadge :label="`${ACCOUNT_KINDS[account.kind].label} · ${account.currency}`" />
                        <StatusBadge v-if="!account.is_active" label="Неактивний" tone="negative" />
                    </div>
                    <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ account.name }}</h1>
                </div>

                <section class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4" aria-label="Баланс">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-14 text-ink-3">Баланс за відстежуваними рухами</span>
                        <span class="num text-30 font-bold">{{ formatMoney(statement.balance, account.currency) }}</span>
                    </div>
                    <div class="flex flex-col divide-y divide-divider border-t border-divider text-15">
                        <div class="flex justify-between gap-3 py-2.5">
                            <span class="text-ink-2">Надходження</span>
                            <span class="num font-semibold text-positive">{{ formatAmount(statement.flows.inbound, { signed: true }) }}</span>
                        </div>
                        <div class="flex justify-between gap-3 py-2.5">
                            <span class="text-ink-2">Перекази</span>
                            <span class="num font-semibold text-negative">{{ formatAmount(`-${statement.flows.outbound}`) }}</span>
                        </div>
                    </div>
                    <template v-if="statement.limit">
                        <ProgressBar :percent="Number(statement.limit.percent)" :warn-from="80" />
                        <span class="flex justify-between gap-3 text-13 text-ink-2">
                            <span>Ліміт {{ formatDate(statement.limit.from) }} – {{ formatDate(statement.limit.to) }}</span>
                            <span class="num">{{ formatAmount(statement.limit.used) }} / {{ formatAmount(statement.limit.limit) }} · {{ statement.limit.percent }} %</span>
                        </span>
                    </template>
                    <p class="m-0 text-12 leading-normal text-ink-3">
                        <span class="num">{{ statement.year }}</span> рік · витрати та особисті зняття не відстежуються
                    </p>
                </section>

                <section v-if="statement.holdings.length" class="flex flex-col gap-2">
                    <SectionTitle>
                        Невиведені надходження
                        <template v-if="can('create', 'account-transfers') && account.is_active" #aside>
                            <RouterLink :to="{ name: 'transfer-new', query: { from: account.id } }" class="flex min-h-11 items-center text-15 font-medium no-underline">
                                Вивести
                            </RouterLink>
                        </template>
                    </SectionTitle>
                    <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                        <RouterLink
                            v-for="holding in statement.holdings"
                            :key="holding.payment_id"
                            :to="{ name: 'payment', params: { id: holding.payment_id } }"
                            class="flex items-center justify-between gap-3 py-3 text-ink no-underline hover:text-ink"
                        >
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="truncate text-15">
                                    <span class="num">{{ formatDayMonth(holding.received_at) }}</span> ·
                                    <span :class="holding.invoice_number ? 'num' : ''">{{ holding.invoice_number ?? holding.description }}</span>
                                </span>
                                <span v-if="holding.is_late && holding.repatriation_deadline" class="text-12 text-warning-text">
                                    {{ quarterLabel(quarterOf(holding.received_at)) }} — мало бути виведено до {{ formatDayMonth(holding.repatriation_deadline) }}
                                </span>
                                <span v-else class="truncate text-12 text-ink-3">{{ holding.client_name }}</span>
                            </span>
                            <span class="num text-15 font-semibold">{{ formatAmount(holding.amount) }}</span>
                        </RouterLink>
                    </div>
                </section>

                <section class="flex flex-col gap-2">
                    <SectionTitle>Рух коштів</SectionTitle>
                    <PeriodStepper
                        :label="`${year} рік`"
                        :can-next="year < now"
                        previous-label="Попередній рік"
                        next-label="Наступний рік"
                        @previous="year -= 1"
                        @next="year += 1"
                    />
                    <EmptyState v-if="statement.history.length === 0" text="Рухів за цей рік немає." />
                    <div v-else class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                        <component
                            :is="entryLink(entry) ? 'RouterLink' : 'div'"
                            v-for="(entry, index) in statement.history"
                            :key="`${entry.type}-${index}`"
                            :to="entryLink(entry) ?? undefined"
                            class="flex items-center justify-between gap-3 py-3 text-ink no-underline hover:text-ink"
                        >
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="truncate text-15">{{ entryTitle(entry) }}</span>
                                <span class="text-12 text-ink-3">
                                    <span class="num">{{ formatDate(entry.date) }}</span>
                                    <template v-if="(entry.type === 'receipt' || entry.type === 'transfer_out') && entry.fee && Number(entry.fee) !== 0">
                                        · комісія <span class="num">{{ formatAmount(entry.fee) }}</span>
                                    </template>
                                    <template v-if="entry.type === 'tax' && entry.status === 'sent'"> · надіслано</template>
                                </span>
                            </span>
                            <span class="num text-15 font-semibold" :class="entry.amount.startsWith('-') ? 'text-negative' : 'text-positive'">
                                {{ formatAmount(entry.amount, { signed: true }) }}
                            </span>
                        </component>
                    </div>
                </section>

                <section v-if="account.holder_name || account.iban || account.account_number || account.details?.length" class="flex flex-col gap-2">
                    <SectionTitle>Реквізити для інвойсів</SectionTitle>
                    <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface pr-1 pl-4">
                        <CopyRow v-if="account.holder_name" label="Отримувач" :value="account.holder_name" :figure="false" />
                        <CopyRow v-if="account.bank_name" label="Банк" :value="account.bank_name" :figure="false" />
                        <CopyRow v-if="account.iban" label="IBAN" :value="account.iban" :shown="mask(account.iban)" />
                        <CopyRow v-if="account.account_number" label="Account number" :value="account.account_number" :shown="mask(account.account_number)" />
                        <CopyRow v-if="account.swift" label="SWIFT / BIC" :value="account.swift" />
                        <CopyRow
                            v-for="detail in account.details ?? []"
                            :key="detail.label"
                            :label="detail.label"
                            :value="detail.value"
                            :shown="/^\d{6,}$/.test(detail.value.replace(/\s/g, '')) ? mask(detail.value) : undefined"
                        />
                    </div>
                </section>

                <RouterLink
                    v-else-if="can('update', 'accounts')"
                    :to="{ name: 'account-edit', params: { id } }"
                    class="flex h-12 items-center justify-center gap-2 rounded-control border border-dashed border-switch-off text-15 font-medium text-accent no-underline"
                >
                    <Icon name="plus" :size="20" />
                    Додати реквізити для інвойсів
                </RouterLink>
            </template>
        </LoadState>
    </SubScreen>
</template>
